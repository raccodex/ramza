const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const UpdateNewPostsController = async (ctx, data, io,socket) => {
  await io.emit("update_new_posts");
};

module.exports = { UpdateNewPostsController };