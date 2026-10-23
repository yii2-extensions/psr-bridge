# Migration checks

The [upgrade guide](../UPGRADE.md) lists the required actions for each release, including the 0.4 runtime requirements,
session isolation (#288), response finalization, range emission, emitter buffers and the 0.2 class renames.

Start with the installed version and the application's runner, then use [configuration](configuration.md) for session
and cookie settings and [practical examples](examples.md) for the emission/finalization pattern. Report validation of
each affected integration point and any server runtime that was not exercised.

[Back to documentation](../README.md#documentation)
