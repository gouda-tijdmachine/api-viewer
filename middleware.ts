// middleware.mjs

export default function middleware(request) {
  // 1. Get the raw URL as a string. 
  // IMPORTANT: The server has already collapsed // to / in the path.
  const rawUrl = request.url; 
  const urlObj = new URL(rawUrl);
  const { origin, search } = urlObj;

  const prefixes = ["/pand", "/persoon", "/foto"];

  // 2. Determine the prefix
  const prefix = prefixes.find((p) => urlObj.pathname.startsWith(p + "/"));
  if (!prefix) return;

  // 3. Extract the identifier manually from the string
  // This takes everything after "/pand/"
  const searchPattern = `${prefix}/`;
  const startIndex = rawUrl.indexOf(searchPattern) + searchPattern.length;
  // We slice the raw string and remove the query params
  let identifier = rawUrl.slice(startIndex).split('?')[0];

  // 4. THE REPAIR:
  // Since the server collapsed 'https://' into 'https:/', we put it back
  // so that encodeURIComponent can do its job properly.
  if (identifier.startsWith("https:/") && !identifier.startsWith("https://")) {
    identifier = identifier.replace("https:/", "https://");
  } else if (identifier.startsWith("http:/") && !identifier.startsWith("http://")) {
    identifier = identifier.replace("http:/", "http://");
  }

  // 5. If it's already encoded (contains %2F or %3A), leave it alone
  if (/%[0-9A-Fa-f]{2}/.test(identifier)) return;

  // 6. Encode the identifier correctly
  // https://n2t.net/... -> https%3A%2F%2Fn2t.net%2F...
  const encoded = encodeURIComponent(identifier);

  // 7. Manually build the destination string
  // Do NOT set urlObj.pathname, as it will re-collapse the slashes.
  const destination = `${origin}${prefix}/${encoded}${search}`;

  // Use 307 (Temporary) while testing to bypass browser cache
  // Change to 308 (Permanent) once confirmed.
  return Response.redirect(destination, 307);
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};