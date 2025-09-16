# Tech Stack Document for code-guide-dev

This document explains the technology choices for the **code-guide-dev** project in everyday language, so that anyone—technical or not—can understand why each tool was picked and how it helps build and maintain this developer guide.

## 1. Frontend Technologies

These are the tools and frameworks that turn simple text files into a polished, navigable website:

- **Markdown**  
  - A lightweight markup language used to write all documentation files (`README.md`, guides, tutorials).  
  - Chosen because it’s easy to read and write, even for non-technical contributors.

- **MkDocs (Material theme)**  
  - A static site generator that converts Markdown files into a full website with navigation, search, and styling.  
  - The Material theme provides a clean layout, responsive design, a collapsible sidebar, and built-in light/dark mode toggling.

- **Sphinx (ReadtheDocs theme, optional)**  
  - An alternative static site generator, often used for API documentation.  
  - Can be added later if we need more advanced documentation features (cross-references, autodoc).

- **HTML / CSS / JavaScript**  
  - Underlying technologies produced by MkDocs/Sphinx.  
  - CSS handles overall look and feel; a small amount of JavaScript powers the sidebar, search box, and theme toggle.

## 2. Backend Technologies

This project does **not** have a traditional server or database. Instead, it uses static site generation and hosting:

- **Static Site Generation**  
  - Both MkDocs and Sphinx run locally (or in a CI pipeline) to read Markdown files and output a folder of static HTML, CSS, and JS files.

- **No Database or Server Framework**  
  - There’s no need for a backend database or application server because all pages are pre-built and served as static assets.

## 3. Infrastructure and Deployment

How code changes turn into a live, accessible website:

- **Git & GitHub**  
  - Source control and repository hosting.  
  - Every change to Markdown or configuration is tracked, and contributors use pull requests to propose edits.

- **GitHub Pages**  
  - Free hosting service for static sites directly from the repository.  
  - Automatically serves the generated documentation at a public URL.

- **GitHub Actions (CI/CD)**  
  - Automated workflows that run on every push or pull request.  
  - Steps include:
    - Installing dependencies (MkDocs, markdownlint, etc.)
    - Running **markdownlint** (and future linter rules) to enforce consistent style
    - Building the static site (MkDocs/Sphinx)
    - Deploying the output to GitHub Pages if checks pass

- **GitHub Codespaces & VS Code Extensions** (development convenience)  
  - Preconfigured cloud dev environments for quick setup.  
  - Extensions like Markdown Preview Enhanced let authors see live previews as they write.

## 4. Third-Party Integrations

Optional services that can enhance the project:

- **AI Writing Assistants (GPT-4, Claude)**  
  - Used by authors to draft or refine guide content, speeding up the writing process.

- **Google Analytics (future)**  
  - Can be added to track which pages are most visited, helping us focus updates where they matter.

- **Accessibility Testing Tools**  
  - External services or browser extensions that check for WCAG compliance (alt text, color contrast).

## 5. Security and Performance Considerations

Keeping the site safe and fast for every visitor:

- **No Secrets in Repo**  
  - No API keys, passwords, or private credentials are ever stored in the repository.  
  - A `.gitignore` file ensures local environment files (e.g., editor configs) are excluded.

- **CI Link & Lint Checks**  
  - Automated tests catch broken links, missing images, or Markdown style violations before deployment.

- **Performance Optimizations**  
  - Static site loads quickly (under 1 second on a typical broadband connection).  
  - Images and other assets can be compressed, and CSS/JS bundles are minified by the build process.

- **Accessibility (a11y)**  
  - Content is written and structured to meet basic WCAG standards (clear headings, alt text for images).  
  - Theme choices ensure sufficient color contrast and keyboard navigability.

## 6. Conclusion and Overall Tech Stack Summary

In summary, **code-guide-dev** leverages a simple yet powerful combination of tools:

- **Markdown** for easy content creation  
- **MkDocs (Material)** (or **Sphinx**) for fast, styled site generation  
- **Git/GitHub** for version control and collaboration  
- **GitHub Pages & Actions** for reliable, automated building and hosting  
- **Linting & CI Checks** to maintain quality, security, and performance

This stack aligns with our goals of clarity, accessibility, and rapid iteration. By choosing static site generators and GitHub’s built-in services, we ensure the project remains low-maintenance, cost-effective, and welcoming to contributors of all skill levels. As the guide grows, we can layer in AI-assisted writing, analytics, and more advanced documentation features without disrupting this solid foundation.