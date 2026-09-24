# BUG-HOSTMACRO — {HOST.HOST} does not resolve in LLD/item-prototype preprocessing scripts

Discovered while running A2b, not planned as its own scenario upfront — written as its own
expected/actual pair per ground rule 2 as soon as the anomaly was noticed and before the
confirming re-test below was run.

Expected (once suspected): if {HOST.HOST} truly doesn't resolve inside these preprocessing
scripts' string literals, then the canonicalPeerId() sysname-fallback branch
(`'s:' + reporterHost + ':' + local_if_index + ':' + sysname`) degrades to using the literal
text `{HOST.HOST}` for EVERY reporter, identically. Two different reporters, each with a
chassis-less neighbor sharing the same local_if_index and the same remote_sysname, would then
collapse into ONE node instead of two — a direct violation of §4.1's explicit requirement that
the s: form "must never become a global sysname match."
