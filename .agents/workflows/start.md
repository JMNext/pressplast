# Role & Objective
Act as a Senior UI/UX Designer and Frontend Developer.
Your goal is to convert the attached reference image
into a pixel-perfect Hero section using code.

# Tech Stack & Constraints
- Use plain HTML5, CSS3, and modern JavaScript (ES6+).
- No frameworks (React/Vue/Bootstrap).
- Use Semantic HTML (e.g., <header>, <nav>, <section>, <h1>).
- Use CSS Variables (:root) for all colors, font sizes,
  and spacing to ensure consistency.
- Use CSS Flexbox and Grid for layout.
- Ensure the design is fully responsive (mobile-first or
  desktop-down approach).

# Design & Assets Instructions
1. **Visual Analysis:** Analyze the reference image
   strictly for:
   - Color palette (extract Hex codes for background,
     primary, secondary, and text colors).
   - Typography (guess the font family or use a close
     Google Fonts alternative like Inter, Roboto, or
     Poppins).
   - Spacing, border-radius, and shadows.
2. **Icons:**
   - Use Iconify Solar Linear icons for UI elements.
3. **Hero Section Requirements:**
   - Recreate the Hero section layout exactly as shown in the reference.
   - Include:
     - Main headline (H1) with strong visual hierarchy.
     - Supporting subtitle or description text.
     - Primary CTA button and optional secondary action.
     - Decorative or illustrative elements if present.
   - Ensure correct alignment, spacing, and visual balance.

4. **Navigation (Header):**
   - Build a responsive header with:
     - Logo (text or image-based).
     - Navigation links.
     - Optional action button (e.g., "Get Started", "Contact").
   - Implement a mobile navigation pattern (hamburger menu).
   - Use pure CSS/JS for interactivity (no libraries).

5. **Layout & Structure:**
   - Use a clear section-based structure:
     - <header>
     - <main>
     - <section>
     - <footer>
   - Apply CSS Grid for macro layout (page structure).
   - Apply Flexbox for micro layout (alignment inside components).

6. **Buttons & UI Components:**
   - Create reusable button styles:
     - Primary
     - Secondary
     - Ghost / Text button (if applicable)
   - Include hover, focus, and active states.
   - Use smooth CSS transitions (ease-in-out).

7. **Animations & Interactions:**
   - Add subtle animations:
     - Hover effects on buttons and links.
     - Entrance animations for Hero content (fade, slide, scale).
   - Use CSS animations and transitions only.
   - Avoid over-animation -- keep it clean and professional.

8. **Responsive Design:**
   - Breakpoints:
     - Mobile (≤ 480px)
     - Tablet (≤ 768px)
     - Desktop (≥ 1024px)
   - Ensure:
     - Text remains readable.
     - Buttons are touch-friendly.
     - Layout adapts without horizontal scrolling.

9. **Accessibility (A11y):**
   - Use proper semantic tags.
   - Ensure sufficient color contrast.
   - Add aria-labels where necessary.
   - Make all interactive elements keyboard-accessible.

10. **Code Quality & Organization:**
    - Separate files:
      - index.html
      - styles.css
      - main.js
    - Use clear class naming (BEM or logical naming).
    - Comment complex sections of code.
    - Avoid inline styles.

11. **Performance Best Practices:**
    - Optimize images (SVG preferred when possible).
    - Minimize unnecessary DOM elements.
    - Avoid heavy shadows or large blur effects.

12. **Final Output:**
    - Deliver a working static webpage.
    - The result must visually match the reference image as closely as possible.
    - The code should be clean, readable, and production-ready.