# Frontend Guideline Document

This document outlines the frontend setup for **code-guide-dev**, our static documentation site. It explains the architecture, design rules, and tools we use to build, style, and maintain the site so that anyone—technical or not—can understand how it all fits together.

## 1. Frontend Architecture

### 1.1 Overview
- We use **MkDocs** (with the Material theme) to turn simple Markdown files into a full-featured website.  
- Under the hood, MkDocs reads your `.md` files and outputs static HTML, CSS, and JavaScript files.  
- There is no traditional JavaScript framework (like React or Vue); instead, the theme’s built-in JS handles things such as the collapsible sidebar, search box, and light/dark mode toggle.

### 1.2 Scalability and Maintainability
- **Content-driven**: All pages live as Markdown files. Adding new content means adding new `.md` files—no need to write or compile complex code.  
- **Modular structure**: We plan folders like `/docs`, `/examples`, and `/src`. Each section lives in its own directory, making it easy to find and update.  
- **Theming and configuration** are centralized in `mkdocs.yml`. Tweaks to colors, fonts, or layout happen in one place, automatically flowing to all pages.

### 1.3 Performance
- Because pages are pre-built, the site loads almost instantly—no server-side work on each request.  
- CSS and JavaScript are minified and served as static assets.  
- Images and other media can be optimized during the build step or via GitHub Actions.

## 2. Design Principles

We follow these core principles when crafting the frontend:

- **Usability**: Clear navigation, straightforward language, and consistent page layouts help users find information quickly.  
- **Accessibility**: We adhere to basic WCAG guidelines—meaning good color contrast, proper heading structure, alt text on images, and keyboard navigation.  
- **Responsiveness**: The site adapts to any screen size, from phone to desktop, thanks to the Material theme’s responsive CSS grid.  
- **Consistency**: Every page follows the same layout—sidebar on the left, header on top, content in the center. This predictability reduces cognitive load.

## 3. Styling and Theming

### 3.1 Styling Approach
- We rely on the **Material theme for MkDocs**, which brings built-in CSS and JavaScript.  
- Any custom styling goes into a small CSS file (e.g., `overrides.css`) referenced in `mkdocs.yml`.  
- No heavy preprocessors (like SASS) are required at this stage, keeping the setup simple.

### 3.2 Theming Details
- **Base style**: Material Design—clean cards, clear typography, and elevation shadows.  
- **Theme mode**: Light and dark modes are supported. Users toggle from the sidebar, and their choice is saved in local storage.  

### 3.3 Color Palette
- Primary color: `#2196F3` (blue)  
- Secondary color: `#FFC107` (amber)  
- Background (light): `#FFFFFF`  
- Background (dark): `#121212`  
- Text (light): `#212121`  
- Text (dark): `#E0E0E0`

### 3.4 Fonts
- **Primary font**: Roboto, the standard for Material Design.  
- **Fallback**: Arial, sans-serif.  
- These are set in the theme configuration; you generally don’t need to tweak them.

## 4. Component Structure

Even though this is a static site, we can think of each part as a component:

- **Page templates**: The Material theme provides a base HTML template with placeholders (header, sidebar, content).  
- **Markdown files**: Each `.md` file corresponds to a content “component.”  
- **Partials/includes**: We can include shared snippets (like a notice box) via MkDocs Macros or Markdown includes.

Why component-based?  
- **Reusability**: Common elements (notices, code blocks, admonitions) render the same wherever you use them.  
- **Maintainability**: Update one partial or CSS override, and every page using it reflects the change.

## 5. State Management

Since this is not a dynamic single-page app, state is minimal:

- **Theme preference**: Stored in the browser’s local storage so light/dark mode persists across visits.  
- **Sidebar state**: The theme’s JavaScript remembers which sections are expanded.

We don’t use Redux or Context API. For our needs, a tiny script embedded by the theme is enough.

## 6. Routing and Navigation

- **Static links**: Every page has its own URL (e.g., `/docs/getting-started/`). Clicking a link loads that HTML file.  
- **Sidebar navigation**: Defined in `mkdocs.yml`. You list your sections and pages, and MkDocs builds the menu automatically.  
- **Search**: Powered by a small JavaScript index generated at build time, letting users quickly jump to topics.

There is no client-side routing library; navigation is handled natively by the browser and simple JS enhancements from the theme.

## 7. Performance Optimization

- **Static site**: No runtime server logic, so page loads are fast.  
- **Minification**: CSS and JS files are minified during the build step.  
- **Lazy loading**: The theme defers loading non-critical assets (like large images) until they scroll into view.  
- **Asset compression**: We can enable gzip or Brotli on GitHub Pages (via a config) or ensure our CI compresses files before deployment.

All of this helps ensure the site loads in under one second on a typical broadband connection.

## 8. Testing and Quality Assurance

We use automated checks to keep our frontend rock solid:

- **markdownlint**: A linter that verifies Markdown style and flags issues in headings, lists, and formatting.  
- **Link checker**: A CI job that scans for broken internal or external links.  
- **CI pipeline (GitHub Actions)**: On each pull request, we:
  1. Install dependencies (MkDocs, markdownlint).
  2. Run markdownlint and fail if there are errors.  
  3. Build the site and run the link checker.  
  4. Optionally deploy to a preview environment.

For future phases, we may add:
- **Visual regression tests** (to catch unintended style changes).  
- **Accessibility audits** using automated tools like axe.

## 9. Conclusion and Overall Frontend Summary

This frontend is built around a simple but powerful pattern: **Markdown + MkDocs (Material theme) + automated CI checks**. It emphasizes:

- **Clarity**: Easy-to-read content and consistent layouts.  
- **Performance**: Pre-built pages, minified assets, and lazy loading.  
- **Accessibility**: WCAG-aware design and keyboard-friendly navigation.  
- **Maintainability**: Modular structure, centralized theming, and automated linting.

By following these guidelines, we ensure that anyone—whether a seasoned engineer or a new contributor—can add, update, and enjoy our documentation site with confidence and ease.