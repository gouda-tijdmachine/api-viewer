// middleware.mjs

export default function middleware(request) {
  // 1. Get the absolute raw URL string
  const rawUrl = request.url; 
  
  const prefixes = ["/pand", "/persoon", "/foto"];
  
  // 2. Find which prefix is being used
  const prefix = prefixes.find(p => rawUrl.includes(`${p}/`));
  if (!prefix) return;

  // 3. Extract the identifier after the prefix
  // Example: .../pand/https:/n2t.net/... -> remainder: "https:/n2t.net/..."
  const searchPattern = `${prefix}/`;
  const startIndex = rawUrl.indexOf(searchPattern) + searchPattern.length;
  let rawRemainder = rawUrl.slice(startIndex).split('?')[0];

  // 4. THE REPAIR: If the infrastructure collapsed https:// to https:/, fix it.
  // This is the critical step for "dumb clients"
  if (rawRemainder.startsWith("https:/") && !rawRemainder.startsWith("https://")) {
    rawRemainder = rawRemainder.replace("https:/", "https://");
  } else if (rawRemainder.startsWith("http:/") && !rawRemainder.startsWith("http://")) {
    rawRemainder = rawRemainder.replace("http:/", "http://");
  }

  // 5. If it's already properly encoded, stop here to avoid loops
  if (/%[0-9A-Fa-f]{2}/.test(rawRemainder)) return;

  // 6. Encode the REPAIRED remainder
  const encodedIdentifier = encodeURIComponent(rawRemainder);

  // 7. Construct destination manually to avoid URL object normalization
  const urlObj = new URL(rawUrl);
  const destination = `${urlObj.origin}${prefix}/${encodedIdentifier}${urlObj.search}`;

  // Use 307 for testing so your browser doesn't cache the result
  return Response.redirect(destination, 307);
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};