export default function middleware(request: Request) {
  const url = new URL(request.url);

  const prefixes = ["/pand", "/persoon", "/foto"];
  const prefix = prefixes.find(
    (p) => url.pathname === p || url.pathname === `${p}/` || url.pathname.startsWith(`${p}/`)
  );
  if (!prefix) return;

  // OPTION 3: /pand?identifier=...  ->  /pand/<encoded>
  if (url.pathname === prefix || url.pathname === `${prefix}/`) {
    const identifier = url.searchParams.get("identifier");
    if (!identifier) return; // or: return new Response("Missing identifier", { status: 400 });

    const dest = new URL(url.toString());
    dest.pathname = `${prefix}/${encodeURIComponent(identifier)}`;
    dest.search = ""; // drop query (optional)
    return Response.redirect(dest.toString(), 308);
  }

  // Everything after "/pand/" (may include slashes/colons)
  const rawRemainder = url.pathname.slice((prefix + "/").length);
  if (!rawRemainder) return;

  // If already percent-encoded, don't touch it
  if (/%[0-9A-Fa-f]{2}/.test(rawRemainder)) return;

  // Repair Vercel's "//" collapse in scheme: "https:/" -> "https://"
  let identifier = rawRemainder
    .replace(/^https:\//, "https://")
    .replace(/^http:\//, "http://");

  // Encode whole remainder as ONE path segment
  const encoded = encodeURIComponent(identifier);

  const dest = new URL(url.toString());
  dest.pathname = `${prefix}/${encoded}`;
  // keep query params if any (usually there aren't here)
  return Response.redirect(dest.toString(), 308);
}

export const config = {
  matcher: ["/pand/:path*", "/persoon/:path*", "/foto/:path*"],
};
