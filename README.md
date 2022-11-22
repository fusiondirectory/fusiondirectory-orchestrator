# Orchestrator

Orchestrator - Middleware - RESTfull API

Performs communication from FusionDirectory (Interface) and its related components.
Components refers to (but not limited at) :
 - Integrator (Include: Tasks Processing)
 - LDAP

# Installation
.htaccess is required for URL Rewriting
.orchestrator.conf is required to store ldap access information as well as SMTP server.
This files is pushed as information but should be located within /etc/orchestrator/orchestrator.conf.

Please follow the installation guide of the User Manual or follow the technical docs for packaging within 
gitlab Wiki.

# Changelog
- 15/08/2022 First commit, ldap methods must be written, first design completed.
- 22/11/2022 Adds orchestrator.conf
