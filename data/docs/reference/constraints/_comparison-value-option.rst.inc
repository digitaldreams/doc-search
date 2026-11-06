``value``
~~~~~~~~~

**type**: ``mixed``

This option is required. It defines the comparison value. It can be a
string, number, array or object.

.. note::

    When passing an array, it must be a list (i.e. an array with consecutive
    integer keys starting from ``0``, such as ``['foo', 'bar', 3]`` or ``[6, 7]``).
    Associative arrays (e.g. ``['key' => 'value']``) are not supported.
