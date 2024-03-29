## %"FusionDirectory Orchestrator 1.0" - 2024-02-23

### Added

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#1 [Orchestrator] - Generic REST API using JWT based authentication with LDAP
- fusiondirectory-orchestrator#2 [Orchestrator] - Task Mail Retrieval and mail process (POC) with PHPMailer as librairy.
- fusiondirectory-orchestrator#3 [Orchestrator] - Review of the overall codebase and align file & classes to match Fusion Directory structure
- fusiondirectory-orchestrator#4 [Orchestrator] - Integrate a new update status report to include the new granular tasks dashboard from FD
- fusiondirectory-orchestrator#5 [Orchestrator] - Verification of the time execution between two SPAM protection timelapse.
- fusiondirectory-orchestrator#9 [Orchestrator] - Create a proper autoload file for the required library dependencies.
- fusiondirectory-orchestrator#21 [Orchestrator] - Evolution of orchestrator - analysis and code review - management of enclosed mail files.

### Changed

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#10 [orchestrator] - Fixes directory architecture in order to align on FD packaging.
- fusiondirectory-orchestrator#11 [Orchestrator] - Verify if DOTenv v3 is retro-compatible with v2.
- fusiondirectory-orchestrator#12 [Orchestrator] - Adapt dotenv code to allow centos library.

### Fixed

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#8 2 htaccess files are exist (one with a typo) does the content must be together
- fusiondirectory-orchestrator#13 [Orchestrator] - Fix the array verification in case signature is not being fulfilled within a mail template.
- fusiondirectory-orchestrator#14 Rename apache configuration file
