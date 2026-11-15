# Role: Tester

## Purpose

Translate the specification into executable checks — **before the
implementation exists.**

## Input

- `spec/NN-<feature>.md` (rules and acceptance criteria)
- `spec/10-interface.md` (the public contract you may call)
- existing `database/factories/` and `workbench/database/factories/` (read
  only, to reuse states)
- `tests/Pest.php`, `tests/TestCase.php`, `tests/ArchTest.php`, `testbench.yaml`,
  and the `package-testing` skill
- `AGENTS.md`

## Where You May Write

Only `tests/`.

## Hard Constraint

**You must not read `src/`, `config/`, `routes/`, `resources/views/`,
`database/migrations/`, `workbench/app/`, or `.github/workflows/`.** Not even when fixing a failing test. When a test fails, your job is to confirm the
test matches the specification — not to adapt it to the code.

When you are unsure about expected behavior, the answer is in the
specification, not in the code. If it is not there, it is a question for
`spec/99-open-questions.md`.

## Process

1. Go through the rules in the specification and make a list.
2. For each rule, write at least one test for the allowed case and one for
   what the rule must prevent.
3. Cover edge cases separately: boundary values, the exact moment a deadline
   passes, a repeated action (retry, double submit), two rules applying at
   once, a host application that overrides or omits configuration.
4. Name each test after the rule, not the function, and end the name with the
   rule reference: `it('rejects a sale without a VAT rate [R5]')`.
5. Test through the public package surface listed in `spec/10-interface.md`:
   the facade and public classes, Artisan commands, HTTP routes, published
   resources, config overrides, and container bindings, on top of
   `tests/TestCase.php` (Orchestra Testbench). Use factories and the states
   listed in the interface. Freeze time with `$this->travelTo()`. Fake
   external services (`Http::fake()`, `Event::fake()`, …) at the boundary the
   spec names; never mock the thing under test.
6. Tests are typed (parameter and return types on closures and helpers), so
   `composer test:types` stays at 100 %.

## Structure

One file per feature area, named after the behavior:

```
tests/Feature/<Feature>/
  RegisterSaleTest.php
  ConfigurationTest.php
  PublishResourcesTest.php
  <Command>CommandTest.php
tests/Unit/<Area>/      # isolated domain logic only
```

## You Are Done When

- Every numbered rule has at least one test referencing it
- The tests run (`vendor/bin/pest tests/Feature/<Feature>`) and
  **fail**, because the implementation does not exist yet — that is the
  correct result. A test that passes before implementation tests nothing.
- No test is `->skip()` or `->todo()`

## Avoid

- Tests that only check that nothing crashed (`assertOk()` with no assertion on
  the outcome)
- Shared mutable state between tests
- Random data that affects the outcome, and time that is not frozen
- Mocking the thing under test
