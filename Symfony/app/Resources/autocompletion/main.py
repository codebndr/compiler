import sys
from logger import *
from request import Request

if __name__ == "__main__":
    if len(sys.argv) != 3:
        log_error(IVK_WRONG_NUM_ARGS)

    # use the syslog utility instead of stderr
    try:
        request = Request(sys.argv[2])
        response = request.get_response()
    except Exception as e:
        log_error(ERR_EXCEPTION, e.message)

    print response.toJSON(int(sys.argv[1]))
