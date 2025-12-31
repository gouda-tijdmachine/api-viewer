// middleware.ts

type Prefix = "/pand" | "/persoon" | "/foto";

const PREFIXES: Prefix[] = ["/pand", "/persoon", "/foto"];

/** true if the string contains at least one %HH escape */
function hasPercentEscapes(s: string): boolean {
  return /%[0-9A-Fa-f]{2}/.test(s);
}

/**
 * Vercel (and other infra) can collapse '//' in path segments.
 * If a URI scheme got collapsed ('https:/' instead of 'https://'), restore it.
 */
function repairCollapsedScheme(s: string): string {
  return s
    .replace(/^https:\//, "https://")
    .replace(/^http:\//, "http://");
}

/**
 * Decide whether the remainder looks like a URI-ish thing that should be encoded.
 * We only redirect if we see clearly unsafe chars for a single path segment.
 */
function needsEncodingForSingleSegment(s: string): boolean {
  // If it already contains percent-escapes, assume it's encoded enough.
  if (hasPercentEscapes(s)) return false;

  // These are the usual culprits for "URI in path segment":
  // ':' and '/' will break path segment semantics.
  // Also encode spaces and some other unsafe chars.
  return /[:\/\s?#\[\]@]/.test(s);
}

function findPrefix(pathname: string): Prefix | null {
  for (const p of PREFIXES) {
    if (pathname === p || pathname === `${p}/` || pathname.startsWith(`${p}/`)) return p;
  }
  return null;
}

/**
 * Extract everything after "/prefix/" from the pathname.
 * Note: pathname is already decoded by URL parsing in some environments,
 * but we treat it as an opaque string and re-encode when needed.
 */
function getRemainder(pathname: string, prefix: string): string {
  const base = prefix.endsWith("/") ? prefix : `${prefix}/`;
  if (!pathname.startsWith(base)) return "";
  return pathname.slice(base.length);
}

export default function middleware(request: Request) {
  const url = new URL(request.url);

  const prefix = findPrefix(url.pathname);
  if (!prefix) return;

  // ---- Option 3: query alias ----
  // /pand?identifier=...  ->  /pand/<encoded-identifier>
  if (url.pathname === prefix || url.pathname === `${prefix}/`) {
    const identifier = url.searchParams.get("identifier");
    if (!identifier) return;

    const repaired = repairCollapsedScheme(identifier);
    const encoded = encodeURIComponent(repaired);

    const dest = new URL(url.toString());
    dest.pathname = `${prefix}/${encoded}`;

    // Remove only the identifier param; keep other params if you want.
    // If you prefer to drop ALL query params, set dest.search = "".
    dest.searchParams.delete("identifier");

    // Avoid redirect loop
    if (dest.pathname === url.pathname && dest.search === url.search) return;

    const res = Response.redirect(dest.toString(), 308);
    res.headers.set("x-mw-hit", "1");
    res.headers.set("x-mw-reason", "query-alias");
    return res;
  }

  // ---- Path normalization ----
  // Everything after /prefix/ (may contain slashes if the client passed an unencoded URI)
  const remainder = getRemainder(url.pathname, prefix);
  if (!remainder) return;

  // Repair collapsed scheme first (https:/ -> https://)
  const repaired = repairCollapsedScheme(remainder);

  // Only encode/redirect if it clearly needs it
  if (!needsEncodingForSingleSegment(repaired)) return;

  const encoded = encodeURIComponent(repaired);

  const dest = new URL(url.toString());
  dest.pathname = `${prefix}/${encoded}`;

  // Avoid redirect loop (paranoia)
  if (dest.pathname === url.pathname) return;

  const res = Response.redirect(dest.toString(), 308);
  res.headers.set("x-mw-hit", "1");
  res.headers.set("x-mw-reason", "encode-path");
  return res;
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};
