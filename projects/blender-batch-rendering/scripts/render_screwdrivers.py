"""Create consistent product renders from a generic STL mesh."""

import math
import os
import sys

import bpy
from mathutils import Vector


argv = sys.argv[sys.argv.index("--") + 1 :]
source_path, output_dir = argv[0], argv[1]
prefix = argv[2] if len(argv) > 2 else None
selected_views = argv[3].split(",") if len(argv) > 3 else ["front", "angle"]
os.makedirs(output_dir, exist_ok=True)
bpy.ops.wm.read_factory_settings(use_empty=True)


def create_material(name, color, metallic=0.0, roughness=0.3):
    material = bpy.data.materials.new(name)
    material.diffuse_color = (*color, 1)
    material.use_nodes = True
    shader = material.node_tree.nodes.get("Principled BSDF")
    shader.inputs["Base Color"].default_value = (*color, 1)
    shader.inputs["Metallic"].default_value = metallic
    shader.inputs["Roughness"].default_value = roughness
    return material


metal = create_material("Brushed metal", (0.48, 0.50, 0.53), 0.85, 0.24)
accent = create_material("Accent polymer", (0.12, 0.34, 0.72), 0.0, 0.3)
bpy.ops.wm.stl_import(filepath=source_path)
obj = bpy.context.object
obj.name = "SampleProduct"
obj.data.materials.append(metal)
obj.data.materials.append(accent)
for polygon in obj.data.polygons:
    polygon.use_smooth = True

# Orient the longest dimension vertically and center the product.
dimensions = list(obj.dimensions)
long_axis = max(range(3), key=lambda axis: dimensions[axis])
if long_axis == 0:
    obj.rotation_euler[1] = math.radians(-90)
elif long_axis == 1:
    obj.rotation_euler[0] = math.radians(90)
bpy.context.view_layer.objects.active = obj
bpy.ops.object.transform_apply(location=False, rotation=True, scale=False)
bounds = [obj.matrix_world @ Vector(corner) for corner in obj.bound_box]
minimum = Vector(map(min, zip(*bounds)))
maximum = Vector(map(max, zip(*bounds)))
obj.location -= Vector(((minimum.x + maximum.x) / 2, (minimum.y + maximum.y) / 2, minimum.z))
bpy.context.view_layer.update()
height = obj.dimensions.z

world = bpy.data.worlds.new("Studio background")
bpy.context.scene.world = world
world.use_nodes = True
world.node_tree.nodes["Background"].inputs["Color"].default_value = (0.01, 0.01, 0.01, 1)
world.node_tree.nodes["Background"].inputs["Strength"].default_value = 0.08


def add_area_light(name, location, energy, size):
    data = bpy.data.lights.new(name, "AREA")
    data.energy = energy
    data.shape = "RECTANGLE"
    data.size = size
    data.size_y = size * 2.5
    light = bpy.data.objects.new(name, data)
    bpy.context.collection.objects.link(light)
    light.location = location
    light.rotation_euler = (Vector((0, 0, height * 0.48)) - light.location).to_track_quat("-Z", "Y").to_euler()


add_area_light("Key", (-height * 0.38, -height * 0.48, height * 0.72), 1800, height * 0.55)
add_area_light("Rim", (height * 0.28, height * 0.18, height * 0.8), 2200, height * 0.35)
add_area_light("Fill", (height * 0.25, -height * 0.38, height * 0.35), 1000, height * 0.45)

camera_data = bpy.data.cameras.new("Camera")
camera = bpy.data.objects.new("Camera", camera_data)
bpy.context.collection.objects.link(camera)
bpy.context.scene.camera = camera
camera_data.type = "ORTHO"
camera_data.ortho_scale = height * 1.18
scene = bpy.context.scene
scene.render.engine = "BLENDER_EEVEE"
scene.render.resolution_x = 600
scene.render.resolution_y = 600
scene.render.resolution_percentage = 100
scene.render.image_settings.file_format = "PNG"
scene.view_settings.look = "AgX - Medium High Contrast"

stem = prefix or os.path.splitext(os.path.basename(source_path))[0]
views = {"front": (0.0, 0.02), "angle": (math.radians(28), math.radians(5))}
for view in selected_views:
    if view not in views:
        raise ValueError("Views must be front and/or angle")
    azimuth, elevation = views[view]
    distance = height * 2
    camera.location = (distance * math.sin(azimuth), -distance * math.cos(azimuth), height * 0.5 + distance * math.sin(elevation))
    camera.rotation_euler = (Vector((0, 0, height * 0.5)) - camera.location).to_track_quat("-Z", "Y").to_euler()
    scene.render.filepath = os.path.join(output_dir, f"{stem}-{view}.png")
    bpy.ops.render.render(write_still=True)
