# Migration checks

The package [UPGRADE guide](../UPGRADE.md) is the canonical consumer migration document. It contains the before/after
changes for the 0.4 release, session isolation correction #288, response finalization, bounded emitter
buffers, and historical class renames. Do not maintain a separate migration checklist here.

Start with the installed version and the application's runner, then use [configuration](configuration.md) for session
and cookie settings and [practical examples](examples.md) for the emission/finalization pattern. Report validation of
each affected integration point and any server runtime that was not exercised.
