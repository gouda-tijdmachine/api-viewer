export default function middleware(request) {
  const { pathname, search, origin } = new URL(request.url);

  const prefixes = ["/pand", "/persoon", "/foto"];

  // 1. Check if the request starts with our prefixes + a slash
  const prefix = prefixes.find((p) => pathname.startsWith(p + "/"));
  if (!prefix) return;

  // 2. Extract the "raw" remainder directly from the pathname string
  // This bypasses any logic that might have already tried to parse it
  const rawRemainder = pathname.slice(prefix.length + 1);

  // 3. Check if it's already encoded
  // We look for %2F (slash) or %3A (colon) specifically
  const isEncoded = /%2F|%3A/i.test(rawRemainder);
  if (!rawRemainder || isEncoded) return;

  // 4. Encode the remainder
  // This turns "https://n2t.net/..." into "https%3A%2F%2Fn2t.net%2F..."
  const encoded = encodeURIComponent(rawRemainder);

  // 5. Construct the final URL string manually
  // Using a string template prevents the URL object from "normalizing" the slashes
  const destination = `${origin}${prefix}/${encoded}${search}`;

  // 6. Redirect
  return Response.redirect(destination, 308);
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};