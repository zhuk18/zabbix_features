## Plan: Replace Duktape with QuickJS-NG (Dual Engine)

Recommended approach: implement QuickJS-NG as a second backend first, keep Duktape as fallback during migration, then cut over after parity and performance validation. This matches your priorities: full scope including Browser bindings, balanced risk and speed, and operational rollback safety.

**Steps**
1. Phase 1 - Baseline and Architecture Lock
1.1 Define backend contract in the embed layer so engine-specific code stays behind one boundary; freeze required semantics for compile/execute, timeouts, memory limits, exception text formatting, global object registration, and native object lifecycle.  
1.2 Decide bytecode strategy for initial parity: source-cache execution for QuickJS-NG (no Duktape bytecode dependency), with compile API preserved at call sites.  
1.3 Add a build-time engine selector with default remaining Duktape until parity is complete.  

2. Phase 2 - Build System and Dependency Plumbing (depends on 1)
2.1 Extend configure checks to detect QuickJS-NG headers/libs and expose conditional defines for selected engine.  
2.2 Update zbxembed library build inputs to compile Duktape backend or QuickJS backend based on selector.  
2.3 Update CI/static-analysis exclusions and packaging rules currently hardcoding Duktape files.  

3. Phase 3 - QuickJS Core Backend Skeleton (depends on 1, parallel with 2.2/2.3 once 2.1 is done)
3.1 Introduce a new backend module implementing runtime/context lifecycle, compile/execute entry points, exception extraction, and timeout interrupt handling.  
3.2 Rework environment internals to carry engine-agnostic handles plus backend-specific runtime/context storage without changing external zbx_es API consumers.  
3.3 Implement compatibility helpers used by existing binding code patterns: function list registration, constructor/super handling, result string conversion, and deep readonly semantics.

4. Phase 4 - Bindings Port (depends on 3; steps 4.2-4.6 can run in parallel by owner)
4.1 Port core Zabbix object and console bindings first to validate constructor/method/finalizer and logging behavior.  
4.2 Port global crypto/base64 helpers and ensure UTF-8 behavior matches current scripts.  
4.3 Port HttpRequest class and HTTPAUTH constants, including libcurl-disabled behavior parity.  
4.4 Port XML object methods and JSON conversion behavior.  
4.5 Port Browser stack (Browser, BrowserError, WebdriverError, Element, Alert, performance helpers), replacing heapptr identity usage with explicit native handle mapping suitable for QuickJS object lifetimes.  
4.6 Preserve all current global names and method signatures to avoid script breakage.

5. Phase 5 - Engine Selection Wiring and Runtime Validation (depends on 2,3,4)
5.1 Route existing initialization flows (core + browser environment) through selected backend with no call-site behavior change.  
5.2 Keep Duktape path intact behind selector for rollback during soak period.  
5.3 Add startup diagnostics/logging that records selected engine and key runtime limits for troubleshooting.

6. Phase 6 - Test and Regression Matrix (depends on 5)
6.1 Unit and integration tests for compile/execute, errors, timeout, memory pressure, and all built-in JS objects/functions under both engines.  
6.2 Browser monitoring end-to-end checks under QuickJS-NG, including object cleanup and error classes.  
6.3 Performance comparison on representative script workloads; define acceptance budget for latency and memory deltas before cutover.

7. Phase 7 - Cutover and Cleanup (depends on 6)
7.1 Switch default engine to QuickJS-NG after parity gates pass; keep Duktape optional for one release window.  
7.2 Remove Duktape vendor sources/headers and dead backend code after rollback window closes.  
7.3 Update documentation and operational runbooks for engine selection, known differences, and rollback procedure.

**Relevant files**
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/embed.c - current engine lifecycle, compile/execute, timeout/error flow; main extraction point.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/embed.h - current helper surface and Duktape-coupled declarations.
- /home/zhuk/work/branches/zabbix/include/zbxembed.h - public API contract that should remain stable for consumers.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/zabbix.c - Zabbix object binding.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/console.c - console binding.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/global.c - global helper functions and encoding behavior.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/httprequest.c - HttpRequest binding and auth constants.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/embed_xml.c - XML binding.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/browser.c - Browser binding and browser env init.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/browser_error.c - browser error class globals.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/browser_element.c - Element object binding.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/browser_alert.c - Alert object binding.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/browser_perf.c - browser perf helpers.
- /home/zhuk/work/branches/zabbix/src/zabbix_js/zabbix_js.c - CLI engine init path and browser env path.
- /home/zhuk/work/branches/zabbix/src/libs/zbxpoller/checks_script.c - server runtime script execution path.
- /home/zhuk/work/branches/zabbix/src/libs/zbxpoller/checks_browser.c - browser monitoring path.
- /home/zhuk/work/branches/zabbix/src/libs/zbxembed/Makefile.am - backend object selection and conditional build wiring.
- /home/zhuk/work/branches/zabbix/configure.ac - feature flag and dependency detection.
- /home/zhuk/work/branches/zabbix/build-backend.xml - static analysis exclusions that reference Duktape files.
- /home/zhuk/work/branches/zabbix/tests/libs/zbxpreproc/Makefile.am - embed-linked tests.
- /home/zhuk/work/branches/zabbix/tests/zabbix_server/trapper/Makefile.am - embed-linked tests.
- /home/zhuk/work/branches/zabbix/tests/zabbix_server/pinger/Makefile.am - embed-linked tests.

**Verification**
1. Build both variants from clean tree: Duktape-selected and QuickJS-NG-selected.
2. Run zabbix_js functional checks covering globals, Zabbix/console/HttpRequest/XML, and browser flows where enabled.
3. Execute existing embed consumers in tests: preprocessing, trapper, and pinger suites.
4. Add parity test vectors for exception text, timeout behavior, and UTF-8 edge strings.
5. Run browser end-to-end checks validating Browser, Element, Alert, BrowserError/WebdriverError object behavior.
6. Compare performance and memory against baseline; require agreed thresholds before default switch.
7. During soak, run canary with QuickJS-NG default and documented fallback to Duktape selector.

**Decisions**
- Engine target: QuickJS-NG as primary replacement.
- Migration mode: dual-engine transition first.
- Scope: complete parity including Browser stack in initial migration program.
- Priority: balanced risk/speed (not maximum conservatism, not rush cutover).
- Included: backend architecture, build/config plumbing, bindings parity, tests, rollout.
- Excluded for first cut: API redesign for script authors, optional module-system enhancements, speculative engine features not required for current parity.

**Further Considerations**
1. QuickJS-NG vendored source vs system package linkage should be fixed early, because it affects reproducibility and distro packaging.
2. Bytecode caching can be revisited after parity; initial source-cache approach reduces schedule risk.
3. Keep one release of rollback support before deleting Duktape to reduce production risk.