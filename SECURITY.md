# Security Policy

## Threat model

context-gate sits between an AI assistant and your application data. It assumes the assistant is untrusted: it may
ask for anything, claim any ceiling, and receive injected text in data it reads. The gate protects against:

- Data above the caller's ceiling reaching the assistant, including through relations, pseudonyms or aggregates.
- Secrets, configuration values and canary values leaving the application in responses, receipts or logs.
- Calls from callers without a valid, unexpired, unrevoked token, or from a browser origin that is not allowed.
- Prompt injection carried in text fields, which is flagged and quarantined but not blocked.

It does not protect against a compromised host application, a leaked token that is still valid, or data you
classify incorrectly.

## Scope

In scope: the `everrest/context-gate` package, its console commands, its MCP stdio and HTTP transports, token
handling, the classification catalog and the firewall pipeline.

Out of scope: your application code, your database and infrastructure, your identity provider, and third-party
assistant clients.

## Supported versions

Security fixes are released for the latest minor version of the current major line. Older versions receive no fixes.
Before 1.0 only the latest release receives fixes.

## Reporting

Do not open a public issue for a security problem. Report it privately through GitHub's private vulnerability reporting
(repository Security tab, then Report a vulnerability), with a description, the affected version and steps to reproduce. Do not include real secrets or personal data in the report; use fake values.

We acknowledge each report within 3 business days and aim to give a first assessment within 10 business days. We
coordinate disclosure with the reporter before publishing a fix.
