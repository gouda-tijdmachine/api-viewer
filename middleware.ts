// middleware.mjs

export default function middleware(request) {
  const rawUrl = request.url;
  const prefixes = ["/pand", "/persoon", "/foto"];

  // 1. Find the prefix
  const prefix = prefixes.find(p => rawUrl.includes(`${p}/`));
  if (!prefix) return;

  // 2. Extract everything after the prefix
  const searchPattern = `${prefix}/`;
  const startIndex = rawUrl.indexOf(searchPattern) + searchPattern.length;
  // Get the identifier and the search params separately
  const [fullIdentifier, search] = rawUrl.slice(startIndex).split('?');

  let identifier = fullIdentifier;

  // 3. REPAIR: Infrastructure often collapses // to /
  // We restore it so encodeURIComponent produces the correct string
  if (identifier.startsWith("https:/") && !identifier.startsWith("https://")) {
    identifier = identifier.replace("https:/", "https://");
  } else if (identifier.startsWith("http:/") && !identifier.startsWith("http://")) {
    identifier = identifier.replace("http:/", "http://");
  }

  // 4. CHECK: If it still contains a literal ":" or "/", it's not encoded enough
  const needsEncoding = identifier.includes(":") || identifier.includes("/");
  if (!needsEncoding) return;

  // 5. ENCODE: This turns "https://n2t.net/ark:/..." into "https%3A%2F%2Fn2t.net%2Fark%3A%2F..."
  const encodedIdentifier = encodeURIComponent(identifier);

  // 6. REDIRECT: Use a string-based URL to prevent the URL object from "helping"
  const urlObj = new URL(rawUrl);
  const searchPart = search ? `?${search}` : "";
  const destination = `${urlObj.origin}${prefix}/${encodedIdentifier}${searchPart}`;

  // Use 307 (Temporary) for testing to avoid local browser cache issues!
  return Response.redirect(destination, 307);
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};