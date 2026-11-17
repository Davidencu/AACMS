#!/bin/bash
# Wrapper around the official MariaDB entrypoint that decides, at every start,
# whether this node bootstraps a new Galera cluster or joins an existing one.
#
#   - Any other peer reachable on the Galera port (4567)  -> join the cluster.
#   - No peer reachable and this node is the bootstrap node (GALERA_BOOTSTRAP=1)
#     -> bootstrap a new cluster (safe on first start and after a clean full stop).
#   - No peer reachable and this node is not the bootstrap node -> wait for one.
set -Eeuo pipefail

NODE_NAME="$(hostname)"
NODE_IP="$(hostname -i | awk '{print $1}')"
PEERS="${GALERA_PEERS:?GALERA_PEERS is required}"
WAIT_SECONDS="${GALERA_JOIN_WAIT:-120}"
GRASTATE=/var/lib/mysql/grastate.dat

peer_up() {
    timeout 2 bash -c "exec 3<>/dev/tcp/$1/4567" 2>/dev/null
}

any_peer_up() {
    local p
    for p in ${PEERS//,/ }; do
        [ "$p" = "$NODE_NAME" ] && continue
        if peer_up "$p"; then
            echo "[galera] peer $p is up"
            return 0
        fi
    done
    return 1
}

BOOT_FLAG=()
if any_peer_up; then
    echo "[galera] $NODE_NAME: joining existing cluster"
elif [ "${GALERA_BOOTSTRAP:-0}" = "1" ]; then
    if [ -f "$GRASTATE" ] && grep -q '^safe_to_bootstrap: 0' "$GRASTATE"; then
        if [ "${GALERA_FORCE_BOOTSTRAP:-0}" = "1" ]; then
            echo "[galera] WARNING: forcing bootstrap from a node that was not the last to stop"
            sed -i 's/^safe_to_bootstrap: 0/safe_to_bootstrap: 1/' "$GRASTATE"
        else
            echo "[galera] ERROR: $GRASTATE says safe_to_bootstrap: 0 and no peer is up." >&2
            echo "[galera] Start the node that stopped last, or set GALERA_FORCE_BOOTSTRAP=1." >&2
            exit 1
        fi
    fi
    echo "[galera] $NODE_NAME: no peers up, bootstrapping a new cluster"
    BOOT_FLAG=(--wsrep-new-cluster)
else
    echo "[galera] $NODE_NAME: waiting up to ${WAIT_SECONDS}s for a peer"
    for _ in $(seq 1 "$((WAIT_SECONDS / 2))"); do
        any_peer_up && break
        sleep 2
    done
    any_peer_up || { echo "[galera] ERROR: no peer became available" >&2; exit 1; }
fi

exec docker-entrypoint.sh mariadbd \
    --wsrep-cluster-address="gcomm://${PEERS}" \
    --wsrep-node-address="${NODE_IP}" \
    --wsrep-node-name="${NODE_NAME}" \
    --wsrep-sst-auth="sst:${GALERA_SST_PASSWORD:?GALERA_SST_PASSWORD is required}" \
    "${BOOT_FLAG[@]}"
