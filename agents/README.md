# Agent Roles

Each file in this directory defines **one role**: its input, what it must
produce, where it may write, and when it is done.

The roles are versioned in the repository on purpose. That turns one
developer's personal trick into a team standard that goes through review like
code. Every role also reads [`AGENTS.md`](../AGENTS.md), which takes
precedence over the role file.

The whole setup rests on two rules:

1. **The model that wrote the code must not review it.** Creating and
   critiquing are separate roles with separate context.
2. **The tester must not see the implementation** — neither the package
   source nor the CI workflows. Otherwise it writes tests that confirm the bugs
   the implementation contains.

| Role | Input | Output | Writes to |
|---|---|---|---|
| [architect](./architect.md) | brief from the human | specification, diagrams, interface | `spec/` |
| [tester](./tester.md) | specification, interface | Pest tests | `tests/` |
| [implementer](./implementer.md) | specification, interface, tests | package code, README, Boost skill | `src/`, `config/`, `database/`, `routes/`, `resources/`, `workbench/`, … |
| [devops](./devops.md) | infrastructure specification (support matrix, CI/CD) | GitHub workflows, Dependabot, release config | `.github/workflows/`, `.github/dependabot.yml`, `.github/release.yml` |
| [reviewer](./reviewer.md) | diff + specification | verdict and findings | nothing |
| [security](./security.md) | diff + dependencies | findings | nothing |

## Workflow

```
brief → architect ⇄ human (open questions) → tester → implementer + devops → reviewer + security → human
```

- Each step starts in a **fresh session** (or subagent) so no role inherits
  another role's context.
- Start a session with: *"Act as the role defined in `agents/<role>.md`.
  Read `AGENTS.md` first."*
- A role that finds a problem outside its own paths reports it and hands it
  back — it never fixes it itself.
