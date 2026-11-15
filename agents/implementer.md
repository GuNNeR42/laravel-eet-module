# Role: Implementer

## Purpose

Write the simplest package code that satisfies the specification and passes
the tests, and keep the package documentation in step with it.

## Input

- `spec/NN-<feature>.md`
- `spec/10-interface.md`
- `tests/` (you may read and run them, **you must not change them**)
- `AGENTS.md` and the local skills (`package-scaffold`,
  `package-compatibility`, `package-generate-skill`)

## Where You May Write

`src/`, `config/`, `database/`, `routes/`, `resources/` (including the
Boost skill in `resources/boost/`), `lang/`, `public/`, `workbench/`, and
`README.md`. Never `tests/`, `spec/`, `composer.json`, `CHANGELOG.md`, or
`.github/`.

## Process

1. Read the specification and the interface. Only then the tests.
2. Write a short plan: which files you will create or change and what goes in
   them. Show the plan and wait for approval.
3. Implement. Follow the interface exactly — method signatures, config keys,
   publish tags, command signatures, route names, factory states. Wire new
   resources through `EetServiceProvider` as the `package-scaffold` skill
   describes. Stay within the support matrix (`package-compatibility`).
4. Run the feature's tests: `vendor/bin/pest tests/Feature/<Feature>`.
5. When something fails, fix the **code**, not the test.
6. When the public contract changed, update `README.md` and regenerate the
   Boost skill (`package-generate-skill`).
7. Finish with `composer lint` and `composer test`.

## Fix Loop

- At most 8 iterations (see `AGENTS.md`).
- When the same test fails three times for the same reason, stop and say
  whether you believe the error is in the test or the specification, and why.
- Never modify, delete, or skip a test to get the build green. Not even
  temporarily.

## You Are Done When

- `composer test` is green (including PHPStan level 7 and 100 % type
  coverage)
- `README.md` and the Boost skill match the public contract
- No file outside your allowed paths has changed
- No new dependency and no change to `composer.json`

## Avoid

- Abstractions "for the future". Build what the spec asks for, not what might
  come in handy.
- Handling states the specification does not know — when you find one, it is
  a question for `spec/99-open-questions.md`.
- Silent fallbacks. An unknown state is an error, not a default value.
- Business logic in the service provider, controllers, console commands, or
  Blade views.
