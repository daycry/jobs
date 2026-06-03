CodeIgniter Jobs — daycry/jobs
==============================

**daycry/jobs** is a job-scheduling and queue-processing library for CodeIgniter 4. You
describe work with a fluent, immutable builder, dispatch it to one of five interchangeable
queue backends, and process it with a resilient worker that provides real (interrupting)
timeouts, retries with backoff, HMAC-signed envelopes, opt-in idempotency, single-instance
locking and per-queue handler allowlists.

This is the Sphinx / Read the Docs entry point. The content pages are authored in Markdown
and rendered through the *MyST* parser (configured in ``conf.py``); the structured table of
contents below mirrors the navigation used by the MkDocs build.

.. note::

   v3.0 is a single, clean architecture. The legacy mutable ``Job`` builder, the V1
   ``Scheduler``, the performance loggers, the ``NotificationService``/email integration,
   the ``QueueManager`` and the ``JobsLogModel`` were all removed. Upgrading from v1? See
   :doc:`MIGRATION-v1-to-v3`.

.. toctree::
   :maxdepth: 2
   :caption: Getting Started

   installation
   quickstart

.. toctree::
   :maxdepth: 2
   :caption: Core Concepts

   ARCHITECTURE
   jobs
   handlers
   QUEUES

.. toctree::
   :maxdepth: 2
   :caption: Guides

   scheduling
   RETRIES
   security
   concurrency
   advanced

.. toctree::
   :maxdepth: 2
   :caption: Operations & CLI

   operations
   COMMANDS

.. toctree::
   :maxdepth: 2
   :caption: Reference

   CONFIGURATION
   ATTEMPTS
   dependencies
   EXCEPTIONS

.. toctree::
   :maxdepth: 1
   :caption: Migration

   MIGRATION-v1-to-v3

Indices and Tables
------------------

* :ref:`genindex`
* :ref:`search`
