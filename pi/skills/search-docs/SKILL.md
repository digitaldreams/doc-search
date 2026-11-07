---
name: search-docs
description: REQUIRED for Symfony, Doctrine, Twig or Laravel work. Your memory of these frameworks is out of date (Symfony 8 removed many APIs). Before writing each PHP class, template, config or command that uses them, and after every error, call the search_docs tool and copy what the docs show.
---

# Search the docs before you code

Your training data predates Symfony 8.x, Doctrine ORM 3.7, Twig 3.x and Laravel 13.x. Code from memory often uses
removed namespaces, old signatures or commands that no longer exist. The `search_docs` tool searches the official
docs on this machine in a few seconds. Use it.

## When

1. Before you write a controller, entity, form, repository, template, migration or test: one search for that piece.
2. Before you run a framework command you are not sure exists.
3. After any error (PHP fatal, failing test, unknown command, 404): search the error's class or message.

## How

Call `search_docs` with:

- `query`: what you want to do in plain words, or an exact class/attribute/option name.
- `docs`: `symfony/symfony-docs` for Symfony, forms, routing, testing, validation, security;
  `doctrine/orm` for entities, mapping, repositories; `doctrine/dbal` for raw SQL and types;
  `twigphp/Twig` for template syntax; `laravel/docs` for Laravel.
- `limit`: leave at 5.

Examples:

- `{"query": "create a route in a controller with an attribute", "docs": "symfony/symfony-docs"}`
- `{"query": "map an entity with attributes and generate a migration", "docs": "symfony/symfony-docs"}`
- `{"query": "functional test submit a form", "docs": "symfony/symfony-docs"}`
- `{"query": "Declaration of buildForm must be compatible", "docs": "symfony/symfony-docs"}`

## Rules

- When the docs and your memory disagree, the docs win. Copy `use` statements and method signatures from them.
- Read the passages before writing code. If none fits, search again with other words.
- Never invent a console command. Check the docs, or run `bin/console list`.
