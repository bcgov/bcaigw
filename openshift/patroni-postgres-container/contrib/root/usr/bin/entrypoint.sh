#!/bin/bash

if [[ $UID -ge 10000 ]]; then
    GID=$(id -g)
    sed -e "s/^postgres:x:[^:]*:[^:]*:/postgres:x:$UID:$GID:/" /etc/passwd > /tmp/passwd
    cat /tmp/passwd > /etc/passwd
    rm /tmp/passwd
fi

# Debug: Print environment variables
echo "=== Environment Debug ==="
echo "PATRONI_POSTGRESQL_DATA_DIR: ${PATRONI_POSTGRESQL_DATA_DIR}"
echo "POD_IP: ${POD_IP}"
echo "PATRONI_NAME: ${PATRONI_NAME}"
echo "PATRONI_SCOPE: ${PATRONI_SCOPE}"
echo "Current user: $(whoami)"
echo "Current UID/GID: $(id)"

# FIX -> FATAL:  data directory "..." has group or world access
# Ensure the full directory structure exists
mkdir -p "$PATRONI_POSTGRESQL_DATA_DIR"
# Also ensure the parent directories are properly created
mkdir -p "$(dirname "$PATRONI_POSTGRESQL_DATA_DIR")"

# Clean up any failed bootstrap attempts to allow fresh initialization
if [ -d "${PATRONI_POSTGRESQL_DATA_DIR}.failed" ]; then
    echo "Removing previous failed bootstrap attempt: ${PATRONI_POSTGRESQL_DATA_DIR}.failed"
    rm -rf "${PATRONI_POSTGRESQL_DATA_DIR}.failed"
fi

# In OpenShift, we can't change ownership but we can set permissions on our own files
# The data directory needs to be accessible only by the postgres user
if [ -d "$PATRONI_POSTGRESQL_DATA_DIR" ]; then
    chmod 700 "$PATRONI_POSTGRESQL_DATA_DIR" 2>/dev/null || echo "Warning: Could not set data directory permissions (expected in OpenShift)"
fi

echo "=== Directory Permissions ==="
ls -la /home/postgres/
ls -la /home/postgres/pgdata/ 2>/dev/null || echo "pgdata directory not accessible"
ls -la /home/postgres/pgdata/pgroot/ 2>/dev/null || echo "pgroot directory doesn't exist yet"

cat > /home/postgres/patroni.yml <<__EOF__
scope: ${PATRONI_SCOPE}
name: ${PATRONI_NAME}

restapi:
  listen: 0.0.0.0:8008
  connect_address: '${POD_IP}:8008'

kubernetes:
  namespace: ${PATRONI_KUBERNETES_NAMESPACE}
  labels:
    cluster-name: ${PATRONI_SCOPE}

bootstrap:
  post_bootstrap: /usr/share/scripts/patroni/post_init.sh
  dcs:
    ttl: 60
    loop_wait: 10
    retry_timeout: 30
    maximum_lag_on_failover: 1048576
    synchronous_mode: false
    synchronous_mode_strict: false
    postgresql:
      use_pg_rewind: true
      use_slots: true
      parameters:
        max_connections: ${POSTGRESQL_MAX_CONNECTIONS:-100}
        max_prepared_transactions: ${POSTGRESQL_MAX_PREPARED_TRANSACTIONS:-0}
        max_locks_per_transaction: ${POSTGRESQL_MAX_LOCKS_PER_TRANSACTION:-64}
        wal_level: replica
        hot_standby: "on"
        wal_keep_size: 128MB
        max_wal_senders: 10
        max_replication_slots: 10
        wal_log_hints: "on"
        archive_mode: "on"
        archive_timeout: 1800s
        archive_command: /bin/true
        # Replica-specific parameters for better bootstrap reliability
        wal_receiver_timeout: 60s
        wal_sender_timeout: 60s
        tcp_keepalives_idle: 600
        tcp_keepalives_interval: 30
        tcp_keepalives_count: 3
  initdb:
  - auth-host: md5
  - auth-local: trust
  - encoding: UTF8
  - locale: en_US.UTF-8
  - data-checksums
  pg_hba:
  - host all all 0.0.0.0/0 md5
  - host replication ${PATRONI_REPLICATION_USERNAME} ${POD_IP}/16 md5

postgresql:
  listen: 0.0.0.0:5432
  connect_address: '${POD_IP}:5432'
  data_dir: ${PATRONI_POSTGRESQL_DATA_DIR}
  authentication:
    superuser:
      username: ${PATRONI_SUPERUSER_USERNAME}
      password: '${PATRONI_SUPERUSER_PASSWORD}'
    replication:
      username: ${PATRONI_REPLICATION_USERNAME}
      password: '${PATRONI_REPLICATION_PASSWORD}'

# Replica configuration for better bootstrap reliability
replica_method: basebackup
basebackup:
  checkpoint: 'fast'
  max-rate: '100M'
  verbose: true

log:
  level: INFO
__EOF__

unset PATRONI_SUPERUSER_PASSWORD PATRONI_REPLICATION_PASSWORD
export KUBERNETES_NAMESPACE=$PATRONI_KUBERNETES_NAMESPACE
export POD_NAME=$PATRONI_NAME

echo "=== Patroni Configuration Generated ==="
echo "Configuration file created at: /home/postgres/patroni.yml"
echo "Scope: ${PATRONI_SCOPE}"
echo "Data directory: ${PATRONI_POSTGRESQL_DATA_DIR}"

echo "=== Starting Patroni ==="
exec /usr/bin/python3 /usr/local/bin/patroni /home/postgres/patroni.yml