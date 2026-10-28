# Banner

`banner.html` is the source of `assets/banner-772x250.png` and `assets/banner-1544x500.png`
(laid out at 772x250 CSS px, palette and photo mark from icon C1).

Render with headless Chrome, once per scale (1 → 772x250, 2 → 1544x500):

    chrome --headless=new --hide-scrollbars --force-device-scale-factor=2 --window-size=772,250 --screenshot=banner.png banner.html

The icon PNGs come from `assets/icon.svg` with `*{animation:none!important}` added to its
`<style>` (frame 0 is the rest state), rendered the same way at 128 and 256.
