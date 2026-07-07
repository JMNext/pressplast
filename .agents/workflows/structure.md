# Structure & Architecture
1. **Semantic HTML5:** Strictly separate the layout into three main areas:
   - '<header>' for the navigation/top bar.
   - '<main>' to wrap the page content. Inside it, place the Hero section using '<section id="hero">'.
   - '<footer>' for the bottom area.
2. **Modularity:** Ensure every section is independent. Do not nest the Hero section inside the Header.
3. **Code Organization:** Add clear comments in both HTML and CSS (e.g., '<!-- === HEADER === -->' and '/* --- Hero Section --- */') to visually separate these blocks.
4. **Layout:** Use a common '.container' class to align content centrally across the Header, Hero, and Footer.