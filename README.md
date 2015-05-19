ShtumiUsefulBundle - make typical things easier
===============================================


## My improvements

1) Imagine that you have a contact editing form. Well. Imagine that it has a field 'Director' which should be displayed as a select list with all contact entities from selected company. But also you need to exclude the contact which is currently being edited from that list. 
It's not a rare case. So you've decided to use the ShtumiUsefulBundle to create One-To-Many relations but... wat da hell? Where is your favourite query_builder inside this? Yes, it was simply removed and I don't give a fuck why.
One of the easiest ways to solve this problem is to send excluded contact id key to the Bundle. With this fork you can forget about this problem with the new parameter 'excluded_entity_id'. For example:

```
            $builder->add('parent', 'shtumi_dependent_filtered_entity', [
                'entity_alias' => 'directors_by_company',
                'parent_field' => 'company',
                'excluded_entity_id' => <<<<your contact's id property>>>> 
            ])
```
NOTE:
currently it's implemented only for DependentFilteredEntity type.

That's all at the moment.
