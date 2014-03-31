import sys, syslog

# number 1 is reserved for clang
REQ_IO_ERROR            = 2
REQ_JSON_LOADS_ERROR    = 3
REQ_KEY_ERROR           = 4
REQ_ATTRIB_ERROR        = 5
REQ_INV_TYPES           = 6
COMPL_TU_LOAD           = 7
IVK_WRONG_NUM_ARGS      = 8

ERR_EXCEPTION           = 9

def log_error(exit_code, msg=""):
    s = ""

    if exit_code == REQ_IO_ERROR:
        s = "REQ_IO_ERROR" \
            "JSON input file reading failed!"
    elif exit_code == REQ_JSON_LOADS_ERROR:
        s = "REQ_JSON_LOADS_ERROR: " \
            "python's JSON module failed to convert input JSON file to dict"
    elif exit_code == REQ_KEY_ERROR:
        s = "REQ_KEY_ERROR: " \
            "The JSON request is missing some key (see _parse_json_data)"
    elif exit_code == REQ_ATTRIB_ERROR:
        s = "REQ_ATTRIB_ERROR: " \
            "JSON's 'command' key is not a split-able string (see _parse_json_data"
    elif exit_code == REQ_INV_TYPES:
        s = " REQ_INV_TYPES: " \
            "Bad type of values in input JSON file (see _parse_json_data)"
    elif exit_code == COMPL_TU_LOAD:
        s = "COMPL_TU_LOAD: " \
            "Clang failed to load the translation unit"
    elif exit_code == IVK_WRONG_ARGS:
        s = "INVK_WRONG_NUM_ARGS: " \
            "The python script has been invoked with wrong arguments" \
            "Usage: ./autocomplete number_of_results path_to_compiler_json"
    elif exit_code == ERR_EXCEPTION:
        s = "ERR_EXCEPTION: " \
            "Unknown exception thrown."


    syslog.syslog(s + '\n' + msg)
    sys.exit(exit_code)
