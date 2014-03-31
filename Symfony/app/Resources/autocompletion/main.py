import sys, syslog
from request import Request

if __name__ == "__main__":
    if len(sys.argv) != 3:
        syslog.syslog("Usage: ./autocomplete nresults autocc.json")
        sys.exit(1)

    request = Request(sys.argv[2])
    response = request.get_response()

    print response.toJSON(int(sys.argv[1]))
    sys.exit(0)
