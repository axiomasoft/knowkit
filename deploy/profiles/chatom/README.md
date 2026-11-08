# Disposable Chatom qualification databases

This profile pins PostgreSQL16/17/18 images by digest. The exact server strings,
PHP8.4.1, Laravel13.40.0 and Chatom Composer references are recorded in
[`matrix.json`](../../../tests/chatom/qualification/v1/matrix.json).

```sh
docker compose -p knowkit-chatom-qualification -f deploy/profiles/chatom/compose.yaml up -d --wait
composer qa -- chatom:qualification
```

Default loopback ports are 55436, 55437 and 55438. Override each with
`CHATOM_TEST_PG16_PORT`, `CHATOM_TEST_PG17_PORT`, or `CHATOM_TEST_PG18_PORT` in
both Compose and the test process if occupied. No existing host database is used.
The fixed `*_test` database, tmpfs and local trust authentication are exclusively
for disposable tests. Do not deploy this profile.

The core schema test runs independently on all three versions. Installed Chatom
driver tests use PG17/18. The separate Edufy PG16 test verifies native doctor
returns unverified catalog proof and **does not mark that host ready**.
The profile starts no relay loop, provider call, application listener or worker.

```sh
docker compose -p knowkit-chatom-qualification -f deploy/profiles/chatom/compose.yaml down --volumes --remove-orphans
```
