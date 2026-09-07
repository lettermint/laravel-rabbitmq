"""Forward test AMQP traffic and drop broker replies after the marker exists."""

import os
import select
import socket
import sys

marker, broker_host, broker_port = sys.argv[1:]
with socket.socket() as listener:
    listener.bind(("127.0.0.1", 0))
    listener.listen(1)
    print(listener.getsockname()[1], flush=True)
    client, _ = listener.accept()
    with client, socket.create_connection((broker_host, int(broker_port))) as broker:
        while True:
            ready, _, _ = select.select([client, broker], [], [], 0.1)
            for source in ready:
                data = source.recv(65536)
                if not data:
                    sys.exit(0)
                if source is broker and os.path.exists(marker):
                    continue
                destination = broker if source is client else client
                destination.sendall(data)
