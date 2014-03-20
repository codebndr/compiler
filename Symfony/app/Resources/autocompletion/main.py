import sys
from request import Request

if __name__ == "__main__":
    sys.stderr = open("/tmp/log.txt", "w")

    if len(sys.argv) != 2:
        print >> sys.stderr, "Wrong number of arguments"
        sys.exit(1)

    req = Request(sys.argv[1])
    resp = req.get_response()
    print >> sys.stderr, resp
    print resp
    sys.exit(0)
