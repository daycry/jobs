# Job Dependencies

You can declare that a job depends on one or more other jobs completing successfully in the **same scheduler run**.

```php
$scheduler->command('generate:report')->everyDay()->named('generate-report');
$scheduler->command('send:report')->everyDay()->dependsOn('generate-report');
$scheduler->command('archive:report')->everyDay()->dependsOn(['generate-report','send-report']);
```

If any dependency fails or is skipped, dependents are not executed.

Combine with `singleInstance()` and notifications for robust workflows.

Next: [Advanced Features](advanced.md)
