import sys
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

        self.typed_chunk = None
        self.chunks = []
        for i in range(cx_completion_string.num_chunks):
            chunk = Chunk(cx_completion_string[i])

            if chunk.is_kind_typed_text:
                self.typed_chunk = chunk

            self.chunks.append(chunk)

    def startswith(self, prefix, case_insensitive=True):
        if self.typed_chunk is None:
            return True

        spelling = self.typed_chunk.spelling

        if case_insensitive:
            prefix = prefix.lower()
            spelling = spelling.lower()

        return spelling.startswith(prefix)

    def contains(self, sub, case_insensitive=True):
        if self.typed_chunk is None:
            return True

        spelling = self.typed_chunk.spelling

        if case_insensitive:
            sub = sub.lower()
            spelling = spelling.lower()

        return sub in spelling

class Result(object):
    def __init__(self, cx_code_completion_result):
        self.cx_code_completion_result = cx_code_completion_result

        self.cursor_kind = CursorKind(cx_code_completion_result.kind)
        self.string = String(cx_code_completion_result.string)

    def startswith(self, prefix, case_insensitive=True):
        return self.string.startswith(prefix, case_insensitive)

    def contains(self, sub, case_insensitive=True):
        return self.string.contains(sub, case_insensitive)

class CodeCompletionResults(object):
    def __init__(self, cx_code_completion_results):
        self.cx_code_completion_results = cx_code_completion_results

        self.diagnostics = \
            convert_diagnostics(cx_code_completion_results.diagnostics)

        self.results = \
            convert_ccr_structure(cx_code_completion_results.results)

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

        self.code_completion = \
            self.TU.codeComplete(self.fname, self.line, self.column)
