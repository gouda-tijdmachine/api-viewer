// middleware.mjs - for dumb clients which don't properly url-encode the identifier

function encodeAsSinglePathSegment(value) {
  // Encode everything so it becomes exactly ONE safe path segment.
  return encodeURIComponent(value);
}

function alreadyLooksEncoded(s) {
  // If it contains percent-escapes, assume it's already encoded enough.
  return /%[0-9A-Fa-f]{2}/.test(s);
}

export default function middleware(request) {
  const url = new URL(request.url);
  const { pathname, searchParams } = url;

  // ---- CONFIG: add more prefixes if needed ----
  const prefixes = ["/pand", "/persoon", "/foto"];
  // --------------------------------------------

  // Find which prefix this request matches
  const prefix = prefixes.find((p) => pathname === p || pathname === p + "/" || pathname.startsWith(p + "/"));
  if (!prefix) return; // do nothing for other routes

  const isBare = pathname === prefix || pathname === prefix + "/";

  // OPTION 3: /pand?identifier=...  ->  /pand/<encoded identifier>
  if (isBare) {
    const identifier = searchParams.get("identifier");
    if (identifier) {
      const encoded = encodeAsSinglePathSegment(identifier);
      url.pathname = `${prefix}/${encoded}`;
      url.search = ""; // drop query string (optional)
      return Response.redirect(url.toString(), 308);
    }
    // If you want bare /pand to be allowed, remove the next two lines:
    // return new Response("Missing identifier", { status: 400 });
    return; // let it fall through to your app
  }

  // PATH NORMALIZATION: /pand/<raw> -> /pand/<encoded>
  // Take everything AFTER the prefix + "/" (can include slashes, colons, etc.)
  const rawRemainder = pathname.slice((prefix + "/").length);

  // If remainder is already encoded, leave it alone
  if (!rawRemainder || alreadyLooksEncoded(rawRemainder)) return;

  // If it contains characters that should be encoded in a single segment,
  // redirect to the encoded version.
  // This also handles cases where the remainder contains slashes.
  const encoded = encodeAsSinglePathSegment(rawRemainder);

  // Avoid redirect loops (paranoia)
  if (encoded === rawRemainder) return;

  url.pathname = `${prefix}/${encoded}`;
  // Keep other query params if any (your call). Usually keep them:
  // url.search stays as-is

  return Response.redirect(url.toString(), 308);
}

// Run only where needed (cheaper/faster)
export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};
