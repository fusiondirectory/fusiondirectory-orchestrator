# Orchestrator

Orchestrator - Middleware - RESTfull API

Performs communication from FusionDirectory (Interface) and its related components.
Components refers to (but not limited at) :
 - Integrator (Include: Tasks Processing)
 - LDAP

# Installation
The file .htaccess at root level is required for URL Rewriting
The file orchestrator.conf is required to store ldap access information as well as SMTP server settings.
This files is pushed as information but should be located within /etc/fusiondirectory-orchestrator/orchestrator.conf.

Please follow the installation guide of the User Manual or follow the technical docs for packaging within 
gitlab Wiki.

# Changelog
Changelog will be generated upon official release and will be located within Changelog.md
