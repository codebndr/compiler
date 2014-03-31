import json

from complete import Completer, CodeCompletionResults
from response import Response
from logger import *

def _read_json_file(p):
    try:
        with open(p, 'r') as f:
            s = f.read()
    except IOError as e:
        log_error(REQ_IO_ERROR)

    return s

def _load_json_string(s):
    try:
        d = json.loads(s)
    except:
        log_error(REQ_JSON_LOADS_ERROR)

    return d

def _parse_json_data(d):
    try:
        fname = d['file']
        line = d['row'];
        column = d['column'];
        prefix = d['prefix'];
        cmd = d['command'].split()

        valid = (isinstance(fname, str) or isinstance(fname, unicode)) and \
                (isinstance(cmd[0], str) or isinstance(cmd[0], unicode)) and \
                (isinstance(prefix, str) or isinstance(prefix, unicode)) and \
                 isinstance(line, int) and (isinstance(column, int))
        if not valid:
            log_error(REQ_INV_TYPES)
    except KeyError as e:
        log_error(REQ_KEY_ERROR)
    except AttributeError as e:
        log_error(REQ_ATTRIB_ERROR)

    # Remove single quotes in filenames and update column position
    # base on the prefix's length
    return (fname.replace("'", ""), line, column - len(prefix), prefix,
            [str(x.replace("'", "")) for x in cmd])

def correct_clang_arguments(fname, args):
    clang_args = ['-c ' + fname]

    # find includes & defines
    for arg in args:
        if arg.startswith('-I') or arg.startswith('-D'):
            clang_args.append(arg)

    return clang_args

def file_len(fname):
    with open(fname) as f:
        for i, l in enumerate(f):
            pass
    return i + 1

def calculate_line_diff(fname):
    import os.path

    ln_diff = 0

    # if the cpp file was produced from an ino file
    # we need to calculate the correct line difference
    if fname.endswith('.cpp') and os.path.isfile(fname[:-4] + '.ino'):
        cpp_lines = file_len(fname)
        ino_lines = file_len(fname[:-4] + '.ino')
        ln_diff = cpp_lines - ino_lines

    return ln_diff

class Request(object):
    def __init__(self, path):
        s = _read_json_file(path)
        d = _load_json_string(s)
        self.fname, self.line, self.column, self.prefix, cmd = _parse_json_data(d)
        self.args = correct_clang_arguments(self.fname, cmd)

    def get_response(self):
        self.line = self.line + calculate_line_diff(self.fname)

        completer = Completer(self.fname, self.line, self.column, self.args)
        code_completion = CodeCompletionResults(completer.code_completion)

        return Response(code_completion, self.prefix);

    def __str__(self):
        ret = ''
        ret = ret + 'file name: ' + self.fname + '\n'
        ret = ret + 'line: ' + str(self.line) + '\n'
        ret = ret + 'column: ' + str(self.column) + '\n'
        ret = ret + 'prefix: ' + str(self.prefix) + '\n'

        ret = ret + 'args:\n'
        for arg in self.args:
            ret = ret + '\t' + arg + '\n'

        return ret
