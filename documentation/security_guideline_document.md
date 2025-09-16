# Security Guidelines for code-guide-dev

This document outlines security considerations and best practices for the **code-guide-dev** repository, which produces a static developer‐guide website. Adhering to these practices will help ensure the integrity, confidentiality, and availability of the documentation and its build pipeline.

---

## 1. Core Security Principles

- **Security by Design**  
  Incorporate security from the initial project setup through every update to documentation and configurations.
- **Least Privilege**  
  Grant only the minimal permissions required for CI workflows, site hosting, and contributor access.
- **Defense in Depth**  
  Apply multiple overlapping controls—static analysis, link checking, CI guards, and deployment restrictions.
- **Fail Securely**  
  When a build or lint check fails, block deployment rather than publishing potentially broken or insecure content.
- **Secure Defaults**  
  Configure all tools (MkDocs, GitHub Pages, Actions) with the most restrictive, secure settings by default.

---

## 2. Repository Configuration

### 2.1. Secrets Management

- Store all credentials (e.g., GitHub Pages deploy tokens) in **GitHub Secrets**, never in code or config files.  
- Restrict CI secrets to only the workflows that require them (use `permissions:` in workflow YAML).  
- Rotate and review secrets periodically.

### 2.2. Branch Protection & Access Control

- Enable **branch protection rules** on `main` to require:  
  - Passing status checks (lint, build)  
  - At least one approver for pull requests  
  - No force pushes or direct commits  
- Use **role-based access** in the organization to limit who can merge or modify CI workflows.

### 2.3. Git and GitHub Hygiene

- Include a `.gitignore` to prevent accidental commits of local environment files, credentials, or editor configs.  
- Enforce a clear **PR template** reminding contributors not to include secrets, images with PII, or unvetted scripts.  
- Require **signed commits** if mandated by your security policy.

---

## 3. CI/CD Security

### 3.1. Workflow Configuration

- Use the official GitHub Actions runners and lock to specific action versions (e.g., `actions/checkout@v3`).  
- Declare minimal `permissions:` scope (e.g., only `contents: read`, `pages: write`).  
- Pin container images or Docker actions by digest to prevent supply‐chain tampering.

### 3.2. Static Analysis & Linting

- Run **markdownlint** (or Vale) in CI to enforce style and catch anomalies (broken links, embedded credentials).  
- Integrate a **link checker** to validate all external `http(s)` links—fail the build on dead or redirecting links.

### 3.3. Build and Deployment Safeguards

- Fail the CI pipeline on any vulnerability scan finding (use Dependabot or a similar SCA tool).  
- Only deploy to GitHub Pages when all checks pass; do not skip or bypass status checks.
- Limit the Pages branch or directory to a dedicated branch (e.g., `gh-pages`) managed by Actions, not by human push.

---

## 4. Static Site Security

### 4.1. Transport Security

- Ensure the live site enforces **HTTPS** (GitHub Pages provides HSTS by default).  
- If using a custom domain, configure TLS 1.2+ and strong cipher suites.

### 4.2. Content Security Policy (CSP)

- Add a CSP header via MkDocs plugin or repository metadata to restrict script and style sources.  
- Example minimal policy:
  ```
  Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self';
  ```

### 4.3. Security Headers

- Enable recommended headers to prevent common risks:
  - `Strict-Transport-Security` (HSTS)  
  - `X-Content-Type-Options: nosniff`  
  - `X-Frame-Options: DENY`  
  - `Referrer-Policy: no-referrer-when-downgrade`

### 4.4. Subresource Integrity (SRI)

- If linking external CSS/JS (e.g., fonts or analytics scripts), include SRI hashes to guard against tampering.

---

## 5. Dependency Management

- Maintain a lockfile (`requirements.txt` or `mkdocs.yml` with pinned versions) to ensure deterministic builds.  
- Subscribe to automated alerts (Dependabot, Renovate) for new vulnerabilities in dependencies.  
- Review and approve dependency updates promptly—prioritize security patches.

---

## 6. Content and Input Safeguards

- Treat all user‐contributed markdown as untrusted:
  - Disallow raw HTML or scripts in markdown via linter or parser settings.  
  - Sanitize any embedded iframes or widgets.
- Enforce no executable code is automatically run in CI; examples must require explicit `make` or local invocation.

---

## 7. Monitoring and Incident Response

- Enable GitHub **audit logs** and review changes to protected branches and secrets.  
- Configure email or Slack notifications for CI failures or dependency alerts.  
- Document an incident‐response plan for compromised secrets or site defacements (e.g., rotate tokens, revert to last known good commit).

---

## 8. Review and Continuous Improvement

- Conduct a security review at each major milestone (e.g., v0.1, v1.0) to ensure controls remain effective.  
- Update this guideline as new threats emerge or the repository’s scope evolves.  
- Encourage contributors to flag security concerns early and provide a direct contact or issue template for reporting vulnerabilities.

---

By following these guidelines, **code-guide-dev** will maintain a robust security posture while providing an open, collaborative environment for documentation and examples.
