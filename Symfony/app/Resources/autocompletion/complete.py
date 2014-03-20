import sys, json, time
# should configure PYTHONPATH environment variable at /etc/apache2/envvars
from clang import cindex
from errors import *

def convert_diagnostics(cx_diagnostics):
    diagnostics = []
    for i in range(len(cx_diagnostics)):
        diagnostics.append(Diagnostic(cx_diagnostics[i]))
    return diagnostics

def convert_ccr_structure(ccr_structure):
    results = []
    for i in range(ccr_structure.numResults):
        results.append(Result(ccr_structure.results[i]))

    return results

class Diagnostic(object):
    def __init__(self, cx_diagnostic):
        self.cx_diagnostic = cx_diagnostic

        self.severity = cx_diagnostic.severity
        self.file = self.cx_diagnostic.location.file.name
        self.line = self.cx_diagnostic.location.line
        self.column = self.cx_diagnostic.location.column
        self.message = self.cx_diagnostic.spelling

class CursorKind(object):
    def __init__(self, cx_cursor_kind):
        self.cx_cursor_kind = cx_cursor_kind

        self.name = cx_cursor_kind.name
        self.value = cx_cursor_kind.value
        self.is_declaration = cx_cursor_kind.is_declaration()
        self.is_reference = cx_cursor_kind.is_reference()
        self.is_expression = cx_cursor_kind.is_expression()
        self.is_statement = cx_cursor_kind.is_statement()
        self.is_attribute = cx_cursor_kind.is_attribute()
        self.is_invalid = cx_cursor_kind.is_invalid()
        self.is_translation_unit = cx_cursor_kind.is_translation_unit()
        self.is_preprocessing = cx_cursor_kind.is_preprocessing()
        self.is_unexposed = cx_cursor_kind.is_unexposed()

# TODO: define Chunk from String's constructor
class Chunk(object):
    def __init__(self, cx_completion_chunk):
        self.cx_completion_chunk = cx_completion_chunk

        self.kind = cx_completion_chunk.kind.name
        self.spelling = cx_completion_chunk.spelling

        self.is_kind_optional = cx_completion_chunk.isKindOptional()
        self.is_kind_typed_text = cx_completion_chunk.isKindTypedText()
        self.is_kind_place_holder = cx_completion_chunk.isKindPlaceHolder()
        self.is_kind_informative = cx_completion_chunk.isKindInformative()
        self.is_kind_result_type = cx_completion_chunk.isKindResultType()

        self.string = None
        if cx_completion_chunk.string is not None:
            self.string = String(cx_completion_chunk.string)

"""
    availability: Available, Deprecated, NotAvailable, NotAccessible
    priority: 0 - 100 (smaller values mean more likely to select)

"""
class String(object):
    def __init__(self, cx_completion_string):
        self.cx_completion_string = cx_completion_string

        self.priority = cx_completion_string.priority
        self.availability = cx_completion_string.availability.name
        self.briefComment = cx_completion_string.briefComment.spelling

        self.chunks = []
        for i in range(cx_completion_string.num_chunks):
            self.chunks.append(Chunk(cx_completion_string[i]))

class Result(object):
    def __init__(self, cx_code_completion_result):
        self.cx_code_completion_result = cx_code_completion_result

        self.cursor_kind = CursorKind(cx_code_completion_result.kind)
        self.string = String(cx_code_completion_result.string)


class CodeCompletionResults(object):
    def __init__(self, cx_code_completion_results):
        self.cx_code_completion_results = cx_code_completion_results

        self.diagnostics = \
            convert_diagnostics(cx_code_completion_results.diagnostics)

        self.results = \
            convert_ccr_structure(cx_code_completion_results.results)

class CodeCompletionEncoder(json.JSONEncoder):
    def default(self, obj):
        if isinstance(obj, Diagnostic):
            return {
                'severity': obj.severity,
                'file': obj.file,
                'line': obj.line,
                'column': obj.column,
                'message': obj.message
            }
        elif isinstance(obj, CursorKind):
            return {
                'name': obj.name,
                'value': obj.value,

                'is_declaration': obj.is_declaration,
                'is_reference': obj.is_reference,
                'is_expression': obj.is_expression,
                'is_statement': obj.is_statement,
                'is_attribute': obj.is_attribute,
                'is_invalid': obj.is_invalid,
                'is_translation_unit': obj.is_translation_unit,
                'is_preprocessing': obj.is_preprocessing,
                'is_unexposed': obj.is_unexposed
            }
        elif isinstance(obj, Chunk):
            return {
                'kind': obj.kind,
                'spelling': obj.spelling,

                'is_kind_optional': obj.is_kind_optional,
                'is_kind_typed_text': obj.is_kind_typed_text,
                'is_kind_place_holder': obj.is_kind_place_holder,
                'is_kind_informative': obj.is_kind_informative,
                'is_kind_result_type': obj.is_kind_result_type,

                'string': obj.string
            }
        elif isinstance(obj, String):
            return {
                'priority': obj.priority,
                'availability': obj.availability,
                'briefComment': obj.briefComment,
                'chunks': obj.chunks
            }
        elif isinstance(obj, Result):
            return {
                'cursor_kind': obj.cursor_kind,
                'string': obj.string
            }
        elif isinstance(obj, CodeCompletionResults):
            return {
                'diagnostics': obj.diagnostics,
                'results': obj.results
            }

        return json.JSONEncoder.default(self, obj)


class Completer(object):
    def __init__(self, fname, line, column, args):
        self.fname = fname
        self.line = line
        self.column = column
        self.args = args

        try:
            self.TU = cindex.TranslationUnit.from_source(fname, args)
        except cindex.TranslationUnitLoadError:
            print >> sys.stderr, "Error: Failed to load Translation Unit"
            sys.exit(COMPL_TU_LOAD)

        self.results = \
            self.TU.codeComplete(self.fname, self.line, self.column)
