ECS deployment
==================


### Usage
```php
// service task new revision
./vendor/bin/ecsdeploy \
    --key="AWS_KEY" \
    --secret="AWS_SECRET" \
    --region="AWS_REGION" \
    --cluster="CLUSTER_NAME" \
    --service="SERVICE_NAME" \
    --task="TASK_NAME" \
    --task-file="TASK_DEFINITION_TEMPLATE" \
    --tasks-amount="TASKS_AMOUNT"
	
// single task
./vendor/bin/ecsdeploy \
    --key="AWS_KEY" \
    --secret="AWS_SECRET" \
    --region="AWS_REGION" \
    --cluster="CLUSTER_NAME" \
    --task="TASK_NAME" \
    --task-file="TASK_DEFINITION_TEMPLATE"
	
```

Deployment script for Amazon ECS

**TODO:**
* add unit tests
* add code comments
* extract main aws client code to separate class
from command
