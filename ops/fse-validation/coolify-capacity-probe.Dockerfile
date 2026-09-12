# Minimal read-only capacity probe. No application code, credentials, DB or host mounts.
FROM busybox:1.37.0
USER 65534:65534
HEALTHCHECK NONE
CMD ["sh", "-c", "date -u; uname -m; cat /proc/meminfo; cat /proc/loadavg; df -Pk /; for p in /sys/fs/cgroup/memory.max /sys/fs/cgroup/cpu.max /sys/fs/cgroup/pids.max; do test ! -r \"$p\" || { echo \"$p\"; cat \"$p\"; }; done; exec sleep 3600"]
