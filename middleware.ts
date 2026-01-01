import { NextRequest, NextResponse } from 'next/server';

type Prefix = "/pand" | "/persoon" | "/foto";
const PREFIXES: Prefix[] = ["/pand", "/persoon", "/foto"];

function hasPercentEscapes(s: string): boolean {
  return /%[0-9A-Fa-f]{2}/.test(s);
}

/**
 * Normalizes the identifier. If Vercel collapsed the scheme (https:/),
 * we fix it here so the resulting encoded string is correct.
 */
function repairCollapsedScheme(s: string): string {
  return s
    .replace(/^https?:\/+(?!\/)/, (match) => match.endsWith('://') ? match : match + '/');
}

function needsEncodingForSingleSegment(s: string): boolean {
  if (hasPercentEscapes(s)) return false;
  // If it contains characters that break path segments, it needs encoding
  return /[:\/\s?#\[\]@]/.test(s);
}

function findPrefix(pathname: string): Prefix | null {
  for (const p of PREFIXES) {
    if (pathname === p || pathname === `${p}/` || pathname.startsWith(`${p}/`)) return p;
  }
  return null;
}

function getRemainder(pathname: string, prefix: string): string {
  const base = prefix.endsWith("/") ? prefix : `${prefix}/`;
  if (!pathname.startsWith(base)) return "";
  return pathname.slice(base.length);
}

export default function middleware(request: NextRequest) {
  const url = new URL(request.url);
  const prefix = findPrefix(url.pathname);

  if (!prefix) return NextResponse.next();

  let targetIdentifier: string | null = null;
  let reason: string = "";

  // ---- Logic Case A: Query Alias (?identifier=...) ----
  if (url.pathname === prefix || url.pathname === `${prefix}/`) {
    const identifier = url.searchParams.get("identifier");
    if (identifier) {
      targetIdentifier = identifier;
      reason = "query-alias";
    }
  } 
  
  // ---- Logic Case B: Path Normalization (/pand/https:/...) ----
  if (!targetIdentifier) {
    const remainder = getRemainder(url.pathname, prefix);
    if (remainder && needsEncodingForSingleSegment(remainder)) {
      targetIdentifier = remainder;
      reason = "encode-path";
    }
  }

  // ---- Execute Redirect if triggered ----
  if (targetIdentifier) {
    const repaired = repairCollapsedScheme(targetIdentifier);
    const encoded = encodeURIComponent(repaired);
    
    const dest = new URL(url.toString());
    dest.pathname = `${prefix}/${encoded}`;
    dest.searchParams.delete("identifier");

    // Prevent infinite loops
    if (dest.pathname === url.pathname && dest.search === url.search) {
      return NextResponse.next();
    }

    // MANUAL RESPONSE CONSTRUCTION
    // This ensures headers stay attached to the 308 redirect
    return new Response(null, {
      status: 308,
      headers: {
        "Location": dest.toString(),
        "x-mw-hit": "1",
        "x-mw-reason": reason,
        "Cache-Control": "no-store, max-age=0", // Ensures headers aren't stripped by intermediate caches
      },
    });
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};
