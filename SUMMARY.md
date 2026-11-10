# Summary of my work

## Scenario 1 : Webhook reliability

In the task I found following issues and take down the point that I am suggesting to implements.
as per my investigation the work on Webhook, I have to prevent webhook from these issues:

### Found issues & implementation plan 
a duplicate dispatch/retry or 2 workers can run simultaniuosly and both set 'processing' and both will do the work.
there is no retry policy (only 1 try)
there can be failure in the middle of processing and skip the completed update, and retry process will execute without knowing what had happen to the first.
Need to update database/migrations/2024_01_01_100000_create_webhook_events_table.php (better to add new migration file) to make unique index of 'provider + event_id' in webhook_events.

in laravel-coding-test/laravel/app/Http/Controllers/WebhookController.php ; 'receive()' use createOrFirst()

In the /app/Jobs/ProcessWebhookJob.php ; handle() need to check the given event id already processing and claim the existing to prevent the same job run simultaneous.
WebhookEvent::whereKey($this->event->id)->where('status', 'pending')->update(['status' => 'processing');

I started with AI-native Claude Code to proceed in-order to get the task complete.

Once after got the code in Manually Authorised mode, I followed through the changes in each file then run through the testing.

### Testing
Both unit tests & manual testing via my terminal:
```
Samurdhis-MacBook-Air:laravel samurdhi$ seq 10 | xargs -P10 -I{} curl -s -X POST http://localhost:35734/api/webhooks/wh2 -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"event_id":"evt_race","type":"t","payload":{"a":1}}'; echo
{"id":33,"status":"pending"}{"id":33,"status":"pending","duplicate":true}{"id":33,"status":"pending","duplicate":true}{"id":33,"status":"pending","duplicate":true}{"id":33,"status":"pending","duplicate":true}{"id":33,"status":"pending","duplicate":true}{"id":33,"status":"pending","duplicate":true}{"id":33,"status":"pending","duplicate":true}{"id":33,"status":"pending","duplicate":true}{"id":33,"status":"pending","duplicate":true}
```



# Scenario 2 — API Performance

In this task I found the following to change & suggested approaches to fix those are:

In 'index()' function trying to get all the orders at once. so once the order table grown this will take more time.
There is N + 1 query issue
No pagination. This has to handle with the limited number of orders at a time.
Indexing are good in the orders tables.

### implementation plan 
Need to use Order::query() with using 'with()' to get limited customer data & item data, and also add paginate()
Add Resource function (OrderResource) to make it as an array collection of items & customer details
Then we can use whenLoaded() to populate the full details of customer & items when actually loading the order tails (inside the pagination).

Same as Webhook task, this I started. as Plan mode with Claude Code and built-up a conversation to improve my suggestions and make sure the Claude's suggesting changes are secured and accurate to apply on this.

Once happy with the plan after few iterative discussion gave a go ahead to apply the code changes in manual authorised mode. Then went through the changes with the discussion with AI agent to make sure doing the right thing.
Run the unit test and process testing manual via terminal. Here is the result that how improved t performance:

### Testing
Before apply the changes:
```
$ curl -s -o /dev/null -w '%{time_total}s  %{size_download} bytes\n' http://localhost:35734/api/orders
0.667468s  634149 bytes
```

after applied the changes:
```
$ curl -s -o /dev/null -w '%{time_total}s  %{size_download} bytes\n' http://localhost:35734/api/orders
0.106212s  17524 bytes
```
So the Response time has improved 6x faster & Payload improved as 36x smaller.