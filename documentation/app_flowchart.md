flowchart TD
  Start[Landed on documentation site] --> Home[View README and links]
  Home --> Contrib{Want to contribute}
  Contrib -->|Yes| Fork[Fork and clone repo]
  Contrib -->|No| Browse[Browse guides and examples]
  Fork --> Branch[Create branch and edit files]
  Branch --> PR[Open pull request]
  PR --> Review[Review and merge]
  Browse --> Sidebar[Navigate sidebar]
  Sidebar --> Section[Click section link]
  Section --> Content[View content]
  Section --> CheckError{Page exists}
  CheckError -->|No| NotFound[Show 404 page]
  CheckError -->|Yes| Content
  Sidebar --> Theme[Toggle theme]
  Theme --> Pref[Save preference]