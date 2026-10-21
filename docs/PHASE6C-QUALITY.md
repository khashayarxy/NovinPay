# Phase 6C — tool evaluation and analysis scope

No project dependency or lockfile was added or changed. No system-wide installation, new MCP server, credentials or broad external access was needed.

| Candidate | Evaluation and action |
| --- | --- |
| Larastan | Official `larastan/larastan` 3.x declares PHP ^8.2, Illuminate ^11.44.2 / ^12.4.1 / ^13, and PHPStan ^2.3.0, so the project's Laravel 13 / PHP 8.4 is compatible. Useful for Eloquent-aware analysis. Project installation is deferred because it needs developer dependency/lockfile approval; no production dependency is proposed. |
| PHPStan | Official standalone **2.3.1** PHAR downloaded outside Git, verified against the GitHub release SHA-256, invoked and used. Level 5 passes for `OrderMoney`, `CommerceInput`, `CommerceAccess`. Level 0 passes for the new service, controllers, request and models. This is a scoped static check; it does not replace full Larastan analysis of dynamic Eloquent properties. |
| Composer / npm audit | Existing supported audit commands executed without fixes or upgrades. Locked PHP dependencies: no advisories/abandoned packages. npm: zero vulnerabilities. JSON results retained outside Git. |
| axe-core | Official Deque npm package **4.14.0** downloaded/unpacked only into external QA files. Used through Playwright on the actual isolated site. Adds repeatable multi-screen WCAG checks beyond one Lighthouse page. No runtime script or package added to the application. |
| DevTools / Lighthouse | Existing MCP reused for console/network, cold-cache traces and mobile Lighthouse. No application profiler dependency or production instrumentation. |
| Laravel Debugbar / Telescope or similar | Not added: isolated `DB::getQueryLog`, real MySQL EXPLAIN, controller render timings and DevTools provide the required evidence without collection/storage of private requests or production overhead. |
| Official Codex skills | Current `openai/skills` curated catalog inspected. No specialized Laravel/PHP skill found. `security-best-practices` explicitly targets Python/JS/TS/Go; no PHP-specific coverage. Generic threat/ownership and duplicate browser skills did not add enough value for this bounded implementation. No skill installed. Existing skill-installer was used only to read the official catalog. |

Official sources checked during the phase: [Larastan requirements](https://github.com/larastan/larastan/blob/3.x/composer.json), [PHPStan releases](https://github.com/phpstan/phpstan/releases), [Composer audit](https://getcomposer.org/doc/03-cli.md#audit), [Deque axe-core](https://github.com/dequelabs/axe-core), [official skill catalog](https://github.com/openai/skills/tree/main/skills/.curated), [security skill language scope](https://github.com/openai/skills/blob/main/skills/.curated/security-best-practices/SKILL.md), [Laravel 13 locking documentation](https://github.com/laravel/docs/blob/13.x/queries.md#pessimistic-locking). No third-party recommendations were installed on trust alone.

## Proposed Larastan scope, requiring separate dependency approval

If the owner approves adding Larastan **only to require-dev**, begin at level 5 on the Phase 6C service, support classes, controllers, request and models, then expand to the application after resolving real errors. Do not blanket-ignore errors or upgrade unrelated packages. Reuse existing Composer autoloading and select an isolated testing environment before any analysis bootstrap; do not connect to the original database.

Example configuration after that approved installation:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    level: 5
    paths:
        - app/Services/CommerceManagement.php
        - app/Support/OrderMoney.php
        - app/Support/CommerceAccess.php
        - app/Support/CommerceInput.php
        - app/Http/Controllers/Admin/InventoryController.php
        - app/Http/Controllers/Admin/OrderController.php
        - app/Http/Requests/CommerceRequest.php
        - app/Models/InventoryItem.php
        - app/Models/InventoryUnit.php
        - app/Models/SalesOrder.php
        - app/Models/SalesOrderLine.php
        - app/Models/SalesOrderEvent.php
        - app/Models/StockMovement.php
```

This example is a proposal, not an installed configuration or a claim that Larastan was executed. The shipped implementation has no additional production or developer package dependency.
