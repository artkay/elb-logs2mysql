# elb-logs2mysql
This script imports Amazon Web Services Elasic Load Balancer logs into a MySQL database, so you can use standard SQL syntax to query and analyze your logs. There are several pricey, enterprise-level solutions for doing this, but they tend to be overkill. This script should suffice for most cases. Any additions/comments are welcome.

# Getting started

First of all, you need to enable the logs on your load balancer. See [Amazon documentation help](http://docs.aws.amazon.com/ElasticLoadBalancing/latest/DeveloperGuide/enable-access-logs.html) for details.

Next, you will need to download the log files and put them into a directory which you pass to the script with the `--dir` parameter.

The easiest way to download all of the logs for the whole day (let's say February 12th, 2016) would be to use [S3 Command Line Tools](http://s3tools.org/s3cmd):

```
cd ~/elb-logs2mysql
cd logs
rm *.log
s3cmd sync s3://<s3-bucket-name>/<elb-name>/AWSLogs/<account-id>/elasticloadbalancing/<aws-region>/2016/02/12/ .
```
Of course you will have to put your values for `s3-bucket-name`, `elb-name` etc.

Don't forget to create an empty database on your local MySQL server.

# Usage

```
php elb-logs2mysql.php --dir <logs directory> --table <table name> [options]
```

### Parameters and options

| Option | Value | Description |
|---|---|---|
| --dir | path/to/directory/with/logs | Directory with the log files; **required**
| --db | database_name | The database to use; **required**
| --table | table_name | The name of the table in which to insert the data; **required**
| --host | host_name | The host to connect to; default is php.ini mysqli default setting
| --user | user_name | The user to connect as; default is php.ini mysqli default setting
| --password | password | The user's password; default is php.ini mysqli default setting
| --create |   | Create table if it doesn't exist
| --drop |   | Drop the existing table if it exists. Implies --create

#Attributions

This script is partially based on [apache2mysql](http://www.startupcto.com/server-tech/apache/importing-apache-httpd-logs-into-mysql) script.