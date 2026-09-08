#!/usr/bin/env bash
set -Eeu

echo "Starting post-bootstrap initialization..."

# Check if required environment variables are set
if [[ "${APP_USER:-}" && "${APP_PASSWORD:-}" && "${APP_DATABASE:-}" ]]; then
  echo "Creating user ${APP_USER}"
  # Create temporary SQL file to avoid logging password
  cat > /tmp/create_user.sql <<EOF
CREATE USER "${APP_USER}" WITH LOGIN ENCRYPTED PASSWORD '${APP_PASSWORD}';
EOF
  
  if psql "$1" -w -f /tmp/create_user.sql >/dev/null 2>&1; then
    echo "User ${APP_USER} created successfully"
  else
    echo "Warning: Failed to create user ${APP_USER} (may already exist)"
  fi
  rm -f /tmp/create_user.sql

  echo "Creating database ${APP_DATABASE}"
  if psql "$1" -w -c "CREATE DATABASE \"${APP_DATABASE}\" OWNER \"${APP_USER}\" ENCODING '${APP_DB_ENCODING:-UTF8}' LC_COLLATE = '${APP_DB_LC_COLLATE:-en_US.UTF-8}' LC_CTYPE = '${APP_DB_LC_CTYPE:-en_US.UTF-8}'" >/dev/null 2>&1; then
    echo "Database ${APP_DATABASE} created successfully"
  else
    echo "Warning: Failed to create database ${APP_DATABASE} (may already exist)"
  fi
else
  echo "Skipping user creation (APP_USER, APP_PASSWORD, or APP_DATABASE not set)"
  echo "Skipping database creation (APP_USER, APP_PASSWORD, or APP_DATABASE not set)"
fi

echo "Post-bootstrap initialization completed successfully"
exit 0
