# FusionDirectory Orchestrator 

FusionDirectory Orchestrator is a RESTful web service using JWT authentication, designed to manage and execute tasks efficiently.

It supports multiple endpoints with plugin integration for custom processing or specialized tasks.

Tasks are defined within FusionDirectory, with a client available to query endpoints and manage workflows.

Common tasks include account lifecycle, notifications, reminders, mail automation, audit log management, and more.

## Features

* Tasks management.
* Tasks execution.
* Workflow management.
* JWT Authentication methods

## Tasks management

FusionDirectory Orchestrator REST API provides seamless management and retrieval of tasks created within FusionDirectory.
It offers a clear and concise view of the status of each task, including subtasks, allowing for detailed tracking and reporting.

With its extensible design, the Orchestrator supports specialized tasks such as mail automation, notifications, reminders,
account lifecycle management, and audit log processing, enabling tailored workflows to meet diverse needs

## Tasks execution

One of the core functionalities of the **FusionDirectory Orchestrator** is the execution and processing of various tasks as defined within FusionDirectory.

- **Mail Tasks**:
  When triggered, tasks of type "Mail" will automatically send the relevant emails if the scheduled conditions are met, ensuring timely communication.

- **Life Cycle Tasks**:
  These tasks are responsible for updating specialized attributes, such as *supann* attributes, in accordance with defined lifecycle processes.

- **Notification Tasks**:
  When attributes are modified, "Notification" tasks will send automated email alerts to keep users informed of changes.

- **Reminder Tasks**:
  These tasks send reminders to users, potentially including links to extend or prolong their account, ensuring critical actions are not missed.

- **Audit Tasks**:
  Tasks of this type allow for the management of audit logs, including the deletion of logs based on configurable retention policies, ensuring compliance and data management.

The **Orchestrator client** provides a user-friendly interface to activate and manage these tasks, allowing for seamless workflow execution and efficient task orchestration across the system.

## Get help

### Community support

There are a couple of ways you can try [to get help][get help].

### Professional support

Professional support is provided through of subscription.

* [FusionDirectory Subscription][subscription-fusiondirectory] : Global subscription for FusionDirectory 

The subscription provides access to FusionDirectory's enterprise repository, tested and pre-packaged versions with patches between versions, 
providing reliable software updates and security enhancements, as well as technical help and support.

Choose the plan that's right for you. Our subscriptions are flexible and scalable according to your needs

The subscription period is one year from the date of purchase and provides you with access to the extensive infrastructure of enterprise-class software and services.

### Best practice badge

[![CII Best Practices](https://bestpractices.coreinfrastructure.org/projects/351/badge)](https://bestpractices.coreinfrastructure.org/projects/351)
  
## Crowfunding

If you like us and want to send us a small contribution, you can use the following crowdfunding services

* [donate-liberapay]

* [donate-kofi]

* [donate-github]
  
## License

[FusionDirectory][FusionDirectory] is  [GPL 2 License](COPYING).

[FusionDirectory]: https://www.fusiondirectory.org/

[fusiondirectory-install]: https://fusiondirectory-user-manual.readthedocs.io/en/latest/fusiondirectory/install/index.html

[get help]: https://fusiondirectory-user-manual.readthedocs.io/en/latest/support/index.html

[subscription-fusiondirectory]: https://www.fusiondirectory.org/en/iam-tool-service-subscriptions/

[register]: https://register.fusiondirectory.org

[donate-liberapay]: https://liberapay.com/fusiondirectory/donate

[donate-kofi]: https://ko-fi.com/fusiondirectory

[donate-github]: https://github.com/fusiondirectory

