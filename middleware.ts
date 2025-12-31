// middleware.mjs
function encodeAsSinglePathSegment(value) {
  // Encodes characters like / : ? & into their %-equivalents
  return encodeURIComponent(value);
}

function alreadyLooksEncoded(s) {
  // Check if the string already contains percent-encoded characters
  return /%[0-9A-Fa-f]{2}/.test(s);
}

export default function middleware(request) {
  const url = new URL(request.url);
  const { pathname } = url;

  const prefixes = ["/pand", "/persoon", "/foto"];

  // 1. Identify which prefix we are dealing with
  const prefix = prefixes.find((p) => pathname.startsWith(p + "/"));
  
  // If the path is exactly the prefix (e.g., "/pand") or no match, skip
  if (!prefix) return;

  // 2. Extract the identifier
  // This takes everything after "/pand/" regardless of how many slashes follow
  const rawRemainder = pathname.slice(prefix.length + 1);

  // 3. Safety Check
  if (!rawRemainder || alreadyLooksEncoded(rawRemainder)) return;

  // 4. Encode the entire remainder
  // "https://n2t.net/..." becomes "https%3A%2F%2Fn2t.net%2F..."
  const encoded = encodeAsSinglePathSegment(rawRemainder);

  // 5. Redirect to the normalized URL
  url.pathname = `${prefix}/${encoded}`;
  
  return Response.redirect(url.toString(), 308);
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};