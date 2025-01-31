## %"FusionDirectory Orchestrator 1.1" - 2025-01-31

### Added

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#39 [Orchestrator] - New endpoint notifications in order to manage notifications tasks
- fusiondirectory-orchestrator#43 [Orchestrator] - Create a possible plugin endpoints, allowing new endpoints developed to be considered as plugin base.
- fusiondirectory-orchestrator#48 [Orchestrator] - Add options mailAuth and mail_sec_verify in orchestrator configuration file
- fusiondirectory-orchestrator#52 [Orchestrator] - AUDIT - automatic deletion from audit tasks
- fusiondirectory-orchestrator#57 [Orchestrator] - New endpoint for userReminder allowing to send notification to use with token to extend their account longevity
- fusiondirectory-orchestrator#62 [Orchestrator] - lifeCycle prolongation adds time from date of process and not from resource end date
- fusiondirectory-orchestrator#64 [Orchestrator] - Reminder - Removal of fdTasksReminderManager
- fusiondirectory-orchestrator#65 [Orchestrator] - Format of the orchestrator.conf updates

### Changed

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#55 [Orchestrator] - Notifications must be updated to be aware of supannStatus values

### Removed

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#37 [Orchestrator] - Analyze the library mail to integrate it to integrator

### Fixed

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#46 [Orchestrator] - taskGateway verify schedule strtotime issues
- fusiondirectory-orchestrator#47 Use overload instead of load for dotenv
- fusiondirectory-orchestrator#61 [Orchestrator] - LifeCycle array supann is analyzed with static numbering
- fusiondirectory-orchestrator#66 [Orchestrator] - user-reminder - issue when only one members is set

## %"FusionDirectory Orchestrator 1.0" - 2024-04-05

### Added

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#1 [Orchestrator] - Generic REST API using JWT based authentication with LDAP
- fusiondirectory-orchestrator#2 [Orchestrator] - Task Mail Retrieval and mail process (POC) with PHPMailer as librairy.
- fusiondirectory-orchestrator#3 [Orchestrator] - Review of the overall codebase and align file & classes to match Fusion Directory structure
- fusiondirectory-orchestrator#4 [Orchestrator] - Integrate a new update status report to include the new granular tasks dashboard from FD
- fusiondirectory-orchestrator#5 [Orchestrator] - Verification of the time execution between two SPAM protection timelapse.
- fusiondirectory-orchestrator#9 [Orchestrator] - Create a proper autoload file for the required library dependencies.
- fusiondirectory-orchestrator#21 [Orchestrator] - Evolution of orchestrator - analysis and code review - management of enclosed mail files.
- fusiondirectory-orchestrator#24 [ORCHESTRATOR] - Supann life cycle - automation via Orchestrator
- fusiondirectory-orchestrator#35 [Orchestrator] - Moving Rest library out to FD-integrator.
- fusiondirectory-orchestrator#41 [Orchestrator] MAIL_SEC="<ssl/tls"> error typo in the orchestrator configuration

### Changed

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#10 [orchestrator] - Fixes directory architecture in order to align on FD packaging.
- fusiondirectory-orchestrator#11 [Orchestrator] - Verify if DOTenv v3 is retro-compatible with v2.
- fusiondirectory-orchestrator#12 [Orchestrator] - Adapt dotenv code to allow centos library.
- fusiondirectory-orchestrator#34 [Orchestrator] - Allows usage of less secure HTTP if configuration file is set without HTTP(s)
- fusiondirectory-orchestrator#42 [Orchestrator] - taskGateway compare lastExec in UTC format with a variable now - set without arg, taking local time instead

### Fixed

#### fusiondirectory-orchestrator
- fusiondirectory-orchestrator#8 2 htaccess files are exist (one with a typo) does the content must be together
- fusiondirectory-orchestrator#13 [Orchestrator] - Fix the array verification in case signature is not being fulfilled within a mail template.
- fusiondirectory-orchestrator#14 Rename apache configuration file

