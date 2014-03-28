import sys, json

def get_entry_availability(availability):
    if availability == "Available":
        return "public"

    return "private"

# ignore optional chunks for now since the ace editor doesn't
# allow the same value attached to multiple captions
def get_value_caption(string):
    caption = ""

    for chunk in string.chunks:
        if chunk.kind == "Equal" or chunk.kind == "ResultType":
            caption = caption + chunk.spelling + " "
        else:
            caption = caption + chunk.spelling

    # in the rather unfortunate case where the string doesn't
    # contain any typed text (!!!), we use the caption itself
    if string.typed_chunk is None:
        value = caption
    else:
        value = string.typed_chunk.spelling

    return (value, caption)


class Entry(object):
    def __init__(self, result):
        self.priority = result.string.priority
        self.availability = get_entry_availability(result.string.availability)
        self.value, self.caption = get_value_caption(result.string)

    def to_dict(self):
        return {
            'v': self.value,
            'c': self.caption,
            'm': self.availability,
            's': self.priority
        }

def get_results_with_prefix(results, prefix):
    return [r for r in results if r.startswith(prefix)]

class Response(object):
    def __init__(self, code_completion, prefix):
        self.code_completion = code_completion
        self.prefix = prefix

    def toJSON(self, number_of_results=100):
        results = self.code_completion.results

        # get the results which have the specified prefix
        results = [
            res for res in results if res.startswith(self.prefix)
        ]

        # sort them in-place by priority
        results.sort(key=lambda res: res.string.priority)

        # trim the final number of results
        self.code_completion.results = results[:number_of_results]

        # get the final entries
        entries = [Entry(r).to_dict() for r in self.code_completion.results]

        return json.dumps(entries)
