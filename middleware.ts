export default function middleware(request) {
  // 1. Get the absolute raw URL string to avoid pre-processing/collapsing
  const rawUrl = request.url; 
  const urlObj = new URL(rawUrl);
  const { origin, search } = urlObj;

  // 2. Define your prefixes
  const prefixes = ["/pand", "/persoon", "/foto"];

  // 3. Find if the path starts with a prefix
  // We use urlObj.pathname here just for the check
  const prefix = prefixes.find((p) => urlObj.pathname.startsWith(p + "/"));
  if (!prefix) return;

  // 4. Extract the remainder from the RAW URL string, not the normalized pathname
  // This ensures we catch "https://" before it becomes "https:/"
  // Logic: find prefix + "/", then take everything after it
  const searchPattern = `${prefix}/`;
  const startIndex = rawUrl.indexOf(searchPattern) + searchPattern.length;
  const rawRemainder = rawUrl.slice(startIndex).split('?')[0]; // exclude query params

  // 5. Validation: If it's already encoded, don't touch it
  if (!rawRemainder || /%[0-9A-Fa-f]{2}/.test(rawRemainder)) return;

  // 6. Force Encode
  // This turns "https://n2t.net/..." into "https%3A%2F%2Fn2t.net%2F..."
  const encoded = encodeURIComponent(decodeURIComponent(rawRemainder));

  // 7. Manual Redirect Construction
  const destination = `${origin}${prefix}/${encoded}${search}`;

  // IMPORTANT: Use a 307 (Temporary) while testing to avoid browser caching!
  // Switch back to 308 (Permanent) once you confirm it works.
  return Response.redirect(destination, 307);
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};